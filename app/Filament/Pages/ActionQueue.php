<?php

namespace App\Filament\Pages;

use App\Models\Anomaly;
use App\Models\Investigation;
use App\Support\Money;
use Filament\Facades\Filament;
use Filament\Pages\Page;

/**
 * Action Queue — the exception firehose made workable.
 *
 * Detection can surface hundreds of investigations on a large tenant. Most of
 * them are portfolio TREND signals (demand erosion, seasonal shifts) that a
 * person reviews in bulk, not one-by-one. This page reframes the raw list into:
 *   • a short "Act this week" list of the highest-value individual incidents, and
 *   • a handful of CAMPAIGNS that roll same-shaped signals together, ranked by value.
 *
 * All figures are deterministic aggregates over the tenant's active anomalies —
 * nothing is generated here. Read-only; it links back to the existing
 * Investigations for the detail.
 */
class ActionQueue extends Page
{
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-bolt';

    protected static \UnitEnum|string|null $navigationGroup = 'Intelligence';

    protected static ?string $navigationLabel = 'Action Queue';

    protected static ?int $navigationSort = 0;

    protected static ?string $slug = 'action-queue';

    protected string $view = 'filament.pages.action-queue';

    public function getTitle(): string
    {
        return 'Action Queue';
    }

    /**
     * Campaign taxonomy: rule_type → campaign. "trend" campaigns are bulk reviews
     * (erosion, seasonality); "incident" campaigns are discrete actions whose top
     * items also feed the Act-this-week list.
     *
     * @var array<string,array{kind:string,accent:string,action:string,rules:array<int,string>}>
     */
    private const CAMPAIGNS = [
        'Demand erosion' => [
            'kind' => 'trend', 'accent' => 'crit', 'action' => 'Review price, promotion or delist',
            'rules' => ['demand_erosion'],
        ],
        'Seasonal shift' => [
            'kind' => 'trend', 'accent' => 'warn', 'action' => 'Align stock to the seasonal curve',
            'rules' => ['demand_seasonality_breach'],
        ],
        'Idle & non-moving stock' => [
            'kind' => 'incident', 'accent' => 'warn', 'action' => 'Rebalance across stores or clear',
            'rules' => ['phantom_inventory', 'dead_stock', 'slow_moving_capital', 'overstock'],
        ],
        'Availability risk' => [
            'kind' => 'incident', 'accent' => 'crit', 'action' => 'Replenish before sales are lost',
            'rules' => ['stockout_risk', 'safety_stock_breach', 'negative_inventory', 'multi_location_imbalance', 'reorder_point_staleness'],
        ],
        'Supply issues' => [
            'kind' => 'incident', 'accent' => 'warn', 'action' => 'Chase the supplier',
            'rules' => ['po_overdue', 'po_late_receipt', 'receiving_discrepancy', 'supplier_fill_rate', 'supplier_lead_time_drift'],
        ],
        'Demand shifts' => [
            'kind' => 'incident', 'accent' => 'teal', 'action' => 'Investigate the movement',
            'rules' => ['sales_drop', 'sales_spike', 'demand_forecast_break', 'cannibalization_signal', 'channel_mix_shift', 'return_rate_spike'],
        ],
        'Margin & pricing' => [
            'kind' => 'incident', 'accent' => 'teal', 'action' => 'Review cost, price and discounting',
            'rules' => ['margin_erosion', 'price_anomaly', 'cost_spike', 'discount_signal'],
        ],
        'Inventory integrity' => [
            'kind' => 'incident', 'accent' => 'teal', 'action' => 'Investigate the loss',
            'rules' => ['inventory_shrinkage', 'cumulative_shrink'],
        ],
        'Data quality' => [
            'kind' => 'incident', 'accent' => 'teal', 'action' => 'Fix the feed or reference data',
            'rules' => ['import_frequency_gap', 'duplicate_transaction_ids', 'sku_master_drift', 'location_proliferation', 'revenue_concentration_risk'],
        ],
    ];

    /** Short verb per rule for the Act-this-week list. */
    private const ACTION_VERB = [
        'stockout_risk' => 'Replenish', 'safety_stock_breach' => 'Replenish', 'negative_inventory' => 'Fix count',
        'multi_location_imbalance' => 'Rebalance', 'phantom_inventory' => 'Clear / transfer',
        'dead_stock' => 'Clear / transfer', 'slow_moving_capital' => 'Clear / transfer', 'overstock' => 'Rebalance',
        'supplier_fill_rate' => 'Chase supplier', 'receiving_discrepancy' => 'Reconcile', 'po_late_receipt' => 'Chase PO',
        'po_overdue' => 'Chase PO', 'supplier_lead_time_drift' => 'Review supplier',
        'inventory_shrinkage' => 'Investigate', 'cumulative_shrink' => 'Investigate',
        'margin_erosion' => 'Review margin', 'price_anomaly' => 'Check price', 'cost_spike' => 'Check cost',
    ];

    public static function getNavigationBadge(): ?string
    {
        $tenantId = Filament::getTenant()?->id;
        if (! $tenantId) {
            return null;
        }
        $n = Investigation::where('tenant_id', $tenantId)
            ->whereIn('status', [Investigation::STATUS_OPEN, Investigation::STATUS_IN_PROGRESS])
            ->count();

        return $n > 0 ? (string) $n : null;
    }

    /**
     * Build the whole view model in one pass over the tenant's active anomalies.
     *
     * @return array<string,mixed>
     */
    public function getQueue(): array
    {
        $tenantId = Filament::getTenant()?->id;
        if (! $tenantId) {
            return ['ready' => false];
        }
        $currency = Filament::getTenant()?->currencyCode();

        // rule → campaign name lookup
        $ruleToCampaign = [];
        foreach (self::CAMPAIGNS as $name => $cfg) {
            foreach ($cfg['rules'] as $rule) {
                $ruleToCampaign[$rule] = $name;
            }
        }

        $anoms = Anomaly::where('tenant_id', $tenantId)->active()
            ->get(['id', 'investigation_id', 'rule_type', 'sku', 'store_id', 'severity', 'context']);

        // Resolve codes → human names (small per-tenant maps).
        $skuNames   = \App\Models\Product::where('tenant_id', $tenantId)->pluck('name', 'sku');
        $storeNames = \App\Models\Store::where('tenant_id', $tenantId)->pluck('name', 'id');
        $nameFor    = fn (?string $sku) => $sku ? ($skuNames[$sku] ?? $sku) : '—';
        $storeFor   = fn ($id) => $id ? ($storeNames[$id] ?? ('Store ' . $id)) : 'chain-wide';

        $camp = [];   // name => aggregate
        $actNow = [];

        foreach ($anoms as $a) {
            $name = $ruleToCampaign[$a->rule_type] ?? 'Other signals';
            $cfg  = self::CAMPAIGNS[$name] ?? ['kind' => 'incident', 'accent' => 'teal', 'action' => 'Review'];
            $val  = (float) ($a->context['revenue_impact'] ?? 0);

            if (! isset($camp[$name])) {
                $camp[$name] = [
                    'name' => $name, 'kind' => $cfg['kind'], 'accent' => $cfg['accent'], 'action' => $cfg['action'],
                    'skus' => [], 'invs' => [], 'value' => 0.0, 'high' => 0, 'examples' => [],
                ];
            }
            $camp[$name]['value'] += $val;
            if ($a->sku) $camp[$name]['skus'][$a->sku] = true;
            if ($a->investigation_id) $camp[$name]['invs'][$a->investigation_id] = true;
            if ($a->severity === Anomaly::SEVERITY_HIGH) $camp[$name]['high']++;
            $camp[$name]['examples'][] = [
                'sku' => $a->sku, 'store' => $a->store_id, 'val' => $val, 'sev' => $a->severity,
            ];

            if (($cfg['kind'] ?? '') === 'incident') {
                $actNow[] = [
                    'rule' => $a->rule_type, 'sku' => $a->sku, 'store' => $a->store_id,
                    'sku_name' => $nameFor($a->sku), 'store_name' => $a->store_id ? $storeFor($a->store_id) : null,
                    'val' => $val, 'sev' => $a->severity, 'campaign' => $name,
                    'verb' => self::ACTION_VERB[$a->rule_type] ?? 'Review',
                ];
            }
        }

        // finalise campaigns: sort examples by value, keep top 3; order cards trend-first then by value
        $campaigns = [];
        foreach ($camp as $c) {
            usort($c['examples'], fn ($x, $y) => $y['val'] <=> $x['val']);
            $campaigns[] = [
                'name'      => $c['name'],
                'kind'      => $c['kind'],
                'accent'    => $c['accent'],
                'action'    => $c['action'],
                'skus'      => count($c['skus']),
                'cases'     => count($c['invs']),
                'high'      => $c['high'],
                'value'     => $c['value'],
                'value_fmt' => Money::compact($c['value'], $currency),
                'examples'  => array_slice(array_map(function ($e) use ($currency, $nameFor, $storeFor) {
                    return [
                        'sku'     => $nameFor($e['sku']),
                        'store'   => $storeFor($e['store']),
                        'val_fmt' => Money::compact($e['val'], $currency),
                    ];
                }, $c['examples']), 0, 3),
            ];
        }
        usort($campaigns, function ($a, $b) {
            $ta = $a['kind'] === 'trend' ? 0 : 1;
            $tb = $b['kind'] === 'trend' ? 0 : 1;
            return $ta <=> $tb ?: $b['value'] <=> $a['value'];
        });

        // act-now: dedupe by rule|sku|store, rank by value, top 8
        $seen = [];
        $uniq = [];
        usort($actNow, fn ($x, $y) => $y['val'] <=> $x['val']);
        foreach ($actNow as $r) {
            $k = $r['rule'] . '|' . $r['sku'] . '|' . $r['store'];
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            $uniq[] = [
                'title'   => self::CAMPAIGNS[$r['campaign']]['rules'] ? $this->incidentTitle($r) : $r['campaign'],
                'sub'     => $r['campaign'],
                'sev'     => $r['sev'],
                'val_fmt' => Money::compact($r['val'], $currency),
                'verb'    => $r['verb'],
            ];
            if (count($uniq) >= 8) break;
        }

        $totalOpen = Investigation::where('tenant_id', $tenantId)
            ->whereIn('status', [Investigation::STATUS_OPEN, Investigation::STATUS_IN_PROGRESS])
            ->count();
        $totalValue = array_sum(array_map(fn ($c) => $c['value'], $campaigns));

        return [
            'ready'          => true,
            'total_open'     => $totalOpen,
            'campaign_count' => count($campaigns),
            'act_count'      => count($uniq),
            'total_value'    => Money::compact($totalValue, $currency),
            'act_now'        => $uniq,
            'campaigns'      => $campaigns,
            'inv_url'        => class_exists(\App\Filament\Resources\InvestigationResource::class)
                ? \App\Filament\Resources\InvestigationResource::getUrl()
                : null,
        ];
    }

    private function incidentTitle(array $r): string
    {
        $where = ! empty($r['store_name']) ? " at {$r['store_name']}" : '';
        $sku   = ! empty($r['sku_name']) ? $r['sku_name'] : ($r['sku'] ?: 'item');

        return match ($r['rule']) {
            'stockout_risk', 'safety_stock_breach' => "Stockout risk — {$sku}{$where}",
            'negative_inventory'                   => "Negative inventory — {$sku}{$where}",
            'phantom_inventory', 'dead_stock', 'slow_moving_capital' => "Idle stock — {$sku}{$where}",
            'overstock'                            => "Overstock — {$sku}{$where}",
            'supplier_fill_rate'                   => "Supplier under-fill — {$sku}",
            'receiving_discrepancy'                => "Receiving gap — {$sku}",
            'po_late_receipt', 'po_overdue'        => "Late PO — {$sku}",
            'inventory_shrinkage', 'cumulative_shrink' => "Shrinkage — {$sku}{$where}",
            'margin_erosion'                       => "Margin erosion — {$sku}",
            'price_anomaly'                        => "Price anomaly — {$sku}",
            default                                => ucwords(str_replace('_', ' ', $r['rule'])) . " — {$sku}{$where}",
        };
    }
}
