<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GatesPageByScreen;
use App\Models\Action;
use App\Models\Anomaly;
use App\Models\AgentRun;
use App\Models\Investigation;
use App\Services\Agents\CampaignActionPlanAgent;
use App\Services\AuditLogger;
use App\Support\Money;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

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
    use GatesPageByScreen;

    const SCREEN_KEY = 'action_queue';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-bolt';

    protected static \UnitEnum|string|null $navigationGroup = 'Intelligence';

    protected static ?string $navigationLabel = 'Action Queue';

    protected static ?int $navigationSort = 0;

    protected static ?string $slug = 'action-queue';

    protected string $view = 'filament.pages.action-queue';

    /** Drill-down: when set, the page shows that campaign's full ranked list. */
    #[Url]
    public ?string $campaign = null;

    /** Main-view tab: null|'campaigns' (default) or 'today' (what changed in 24h). */
    #[Url]
    public ?string $tab = null;

    /** Bulk-review: investigation ids selected in the campaign drill-down. */
    public array $selected = [];

    /**
     * Per-campaign review cadence in days — the SLA/tempo. A campaign is "due"
     * when it has never been reviewed or was last reviewed more than this ago.
     * Urgent, incident-shaped campaigns get a tight cadence; bulk trend reviews
     * a loose one.
     */
    private const CAMPAIGN_CADENCE_DAYS = [
        'Availability risk'       => 1,
        'Supply issues'           => 2,
        'Demand shifts'           => 3,
        'Margin & pricing'        => 7,
        'Idle & non-moving stock' => 7,
        'Inventory integrity'     => 7,
        'Data quality'            => 7,
        'Demand erosion'          => 7,
        'Seasonal shift'          => 14,
    ];

    /** Window for the "Today / what changed" view. */
    private const TODAY_WINDOW_HOURS = 24;

    /** Campaign → the remediation Action type a manual bulk "start" creates. */
    private const BULK_ACTION_TYPE = [
        'Demand erosion'          => Action::TYPE_PRICE_ADJUSTMENT,
        'Seasonal shift'          => Action::TYPE_REORDER,
        'Idle & non-moving stock' => Action::TYPE_TRANSFER,
        'Availability risk'       => Action::TYPE_REORDER,
        'Supply issues'           => Action::TYPE_SUPPLIER_CONTACT,
        'Demand shifts'           => Action::TYPE_INVESTIGATE_FURTHER,
        'Margin & pricing'        => Action::TYPE_PRICE_ADJUSTMENT,
        'Inventory integrity'     => Action::TYPE_INVESTIGATE_FURTHER,
        'Data quality'            => Action::TYPE_OTHER,
    ];

    public function getTitle(): string
    {
        return 'Action Queue';
    }

    private function investigateUrl(int $investigationId): string
    {
        return \App\Filament\Resources\InvestigationResource::getUrl('investigate', ['record' => $investigationId]);
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

    // ── Taxonomy accessors ──────────────────────────────────────────────────────
    // One source of truth for the campaign taxonomy. The Campaign Action-Plan
    // agent reads these so it never re-declares the rule→campaign mapping.

    /** @return array<int,string> the rule_types that make up a campaign */
    public static function rulesForCampaign(string $campaignName): array
    {
        return self::CAMPAIGNS[$campaignName]['rules'] ?? [];
    }

    /** @return array{kind:string,accent:string,action:string} */
    public static function campaignMeta(string $campaignName): array
    {
        $c = self::CAMPAIGNS[$campaignName] ?? [];

        return [
            'kind'   => $c['kind']   ?? 'incident',
            'accent' => $c['accent'] ?? 'teal',
            'action' => $c['action'] ?? 'Review',
        ];
    }

    /** @return array<string,array> the whole campaign map */
    public static function campaignsMap(): array
    {
        return self::CAMPAIGNS;
    }

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

        // SLA/cadence: when was each campaign last reviewed for this tenant?
        $reviews = \App\Models\CampaignReview::where('tenant_id', $tenantId)->pluck('last_reviewed_at', 'campaign');

        // finalise campaigns: sort examples by value, keep top 3; attach SLA state.
        $campaigns = [];
        foreach ($camp as $c) {
            usort($c['examples'], fn ($x, $y) => $y['val'] <=> $x['val']);
            $sla = $this->campaignSla($c['name'], $reviews[$c['name']] ?? null);
            $campaigns[] = [
                'name'         => $c['name'],
                'kind'         => $c['kind'],
                'accent'       => $c['accent'],
                'action'       => $c['action'],
                'skus'         => count($c['skus']),
                'cases'        => count($c['invs']),
                'high'         => $c['high'],
                'value'        => $c['value'],
                'value_fmt'    => Money::compact($c['value'], $currency),
                'due'          => $sla['due'],
                'sla_label'    => $sla['label'],
                'examples'  => array_slice(array_map(function ($e) use ($currency, $nameFor, $storeFor) {
                    return [
                        'sku'     => $nameFor($e['sku']),
                        'store'   => $storeFor($e['store']),
                        'val_fmt' => Money::compact($e['val'], $currency),
                    ];
                }, $c['examples']), 0, 3),
            ];
        }
        // Order: due-first (the SLA tempo), then by value at risk.
        usort($campaigns, function ($a, $b) {
            return ($b['due'] <=> $a['due']) ?: ($b['value'] <=> $a['value']);
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

        // Drill-down: the selected campaign's full list, ranked by value, each row
        // linking to its investigation. Built from the same anomaly set.
        $detail = null;
        if ($this->campaign) {
            // Opening a campaign counts as reviewing it — advances its SLA clock.
            \App\Models\CampaignReview::updateOrCreate(
                ['tenant_id' => $tenantId, 'campaign' => $this->campaign],
                ['last_reviewed_at' => now()]
            );

            $rows = [];
            foreach ($anoms as $a) {
                if (($ruleToCampaign[$a->rule_type] ?? 'Other signals') !== $this->campaign) continue;
                $rows[] = [
                    'sku'   => $nameFor($a->sku),
                    'store' => $storeFor($a->store_id),
                    'sev'   => $a->severity,
                    'val'   => (float) ($a->context['revenue_impact'] ?? 0),
                    'inv'   => $a->investigation_id,
                ];
            }
            usort($rows, fn ($x, $y) => $y['val'] <=> $x['val']);
            $detail = [
                'name'  => $this->campaign,
                'count' => count($rows),
                'value' => Money::compact(array_sum(array_column($rows, 'val')), $currency),
                'plan'  => $this->planViewModel($this->campaign),
                'rows'  => array_map(fn ($r) => [
                    'id'      => $r['inv'],
                    'sku'     => $r['sku'],
                    'store'   => $r['store'],
                    'sev'     => $r['sev'],
                    'val_fmt' => Money::compact($r['val'], $currency),
                    'url'     => $r['inv'] ? $this->investigateUrl($r['inv']) : null,
                ], array_slice($rows, 0, 200)),
            ];
        }

        // "Today / what changed" view — only built when that tab is active.
        $today = $this->tab === 'today'
            ? $this->todayRows($tenantId, $currency, $skuNames, $storeNames)
            : [];

        return [
            'ready'          => true,
            'selected'       => $this->campaign,
            'detail'         => $detail,
            'tab'            => $this->tab === 'today' ? 'today' : 'campaigns',
            'total_open'     => $totalOpen,
            'campaign_count' => count($campaigns),
            'due_count'      => count(array_filter($campaigns, fn ($c) => $c['due'])),
            'act_count'      => count($uniq),
            'total_value'    => Money::compact($totalValue, $currency),
            'act_now'        => $uniq,
            'campaigns'      => $campaigns,
            'today'          => $today,
            'today_count'    => count($today),
            'campaigns_url'  => self::getUrl(),
            'today_url'      => self::getUrl(['tab' => 'today']),
            'back_url'       => self::getUrl(),
        ];
    }

    /**
     * SLA state for a campaign given its last-reviewed timestamp.
     *
     * @return array{due:bool,label:string}
     */
    private function campaignSla(string $campaign, $lastReviewed): array
    {
        $cadence = self::CAMPAIGN_CADENCE_DAYS[$campaign] ?? 7;

        if (! $lastReviewed) {
            return ['due' => true, 'label' => 'Not yet reviewed'];
        }

        $last = $lastReviewed instanceof \Carbon\Carbon
            ? $lastReviewed
            : \Carbon\Carbon::parse($lastReviewed);

        $due = $last->copy()->addDays($cadence)->isPast();

        return [
            'due'   => $due,
            'label' => $due
                ? 'Due · last reviewed ' . $last->diffForHumans()
                : 'Reviewed ' . $last->diffForHumans(),
        ];
    }

    /**
     * The "Today" delta: open investigations that were opened OR escalated inside
     * the window (default 24h), ranked by value. This is the true daily number —
     * small in steady state — versus the standing backlog the campaigns show.
     *
     * @return array<int,array<string,mixed>>
     */
    private function todayRows(int $tenantId, ?string $currency, $skuNames, $storeNames): array
    {
        $since = now()->subHours(self::TODAY_WINDOW_HOURS);

        $invs = Investigation::where('tenant_id', $tenantId)
            ->whereIn('status', [Investigation::STATUS_OPEN, Investigation::STATUS_IN_PROGRESS])
            ->where(fn ($q) => $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now()))
            ->where(function ($q) use ($since) {
                $q->where('opened_at', '>=', $since)
                    ->orWhereHas('escalationEvents', fn ($e) => $e->where('created_at', '>=', $since));
            })
            ->orderByDesc('revenue_at_risk')
            ->limit(60)
            ->get(['id', 'title', 'primary_sku', 'primary_store_id', 'revenue_at_risk', 'priority', 'opened_at']);

        $rows = [];
        foreach ($invs as $inv) {
            $isNew = $inv->opened_at && $inv->opened_at->gte($since);
            $rows[] = [
                'title'    => $inv->title
                    ?: ($inv->primary_sku ? ($skuNames[$inv->primary_sku] ?? $inv->primary_sku) : ('Investigation #' . $inv->id)),
                'sub'      => $inv->primary_store_id ? ($storeNames[$inv->primary_store_id] ?? ('Store ' . $inv->primary_store_id)) : null,
                'val_fmt'  => Money::compact((float) $inv->revenue_at_risk, $currency),
                'priority' => $inv->priority,
                'tag'      => $isNew ? 'new' : 'escalated',
                'url'      => $this->investigateUrl($inv->id),
            ];
        }

        return $rows;
    }

    // ── Campaign Action-Plan agent (Agent #1) ────────────────────────────────────
    // The AI drafts a plan; a human accepts; execution is deterministic and stays
    // inside Autnyx (Actions + status changes + PO-draft export). Never ERP.

    /**
     * The current plan for a campaign, shaped for the view. Returns null when
     * there is no live plan (none yet, or the last one was dismissed) so the
     * panel shows the "Draft plan" call to action.
     */
    private function planViewModel(string $campaign): ?array
    {
        $tenantId = Filament::getTenant()?->id;
        if (! $tenantId) {
            return null;
        }

        $run = AgentRun::where('tenant_id', $tenantId)
            ->where('agent_key', AgentRun::KEY_CAMPAIGN_PLAN)
            ->where('subject_type', 'campaign')
            ->where('subject_id', $campaign)
            ->latest('id')
            ->first();

        if (! $run || $run->status === AgentRun::STATUS_DISMISSED) {
            return null;
        }

        return [
            'state'            => $run->status,
            'run_id'           => $run->id,
            'objective'        => $run->out('objective'),
            'summary'          => $run->out('summary'),
            'steps'            => $run->out('steps', []),
            'priority_focus'   => $run->out('priority_focus'),
            'expected_outcome' => $run->out('expected_outcome'),
            'watchouts'        => $run->out('watchouts', []),
            'confidence'       => $run->confidence,
            'model'            => $run->model,
            'generated_at'     => optional($run->created_at)->diffForHumans(),
            'execution'        => $run->out('execution'),
            'po_available'     => ! empty($run->out('po_draft.lines')),
            'acted_by'         => $run->actedBy?->name,
            'executed_at'      => optional($run->executed_at)->diffForHumans(),
            'error'            => $run->error,
        ];
    }

    /** Scope an incoming run id to this tenant + this campaign — never trust the client. */
    private function guardRun(int $runId): ?AgentRun
    {
        $tenantId = Filament::getTenant()?->id;
        if (! $tenantId || ! $this->campaign) {
            return null;
        }

        return AgentRun::where('id', $runId)
            ->where('tenant_id', $tenantId)
            ->where('agent_key', AgentRun::KEY_CAMPAIGN_PLAN)
            ->where('subject_id', $this->campaign)
            ->first();
    }

    /** Draft (or re-draft) the plan for the campaign currently in view. */
    public function draftPlan(): void
    {
        $tenantId = Filament::getTenant()?->id;
        if (! $tenantId || ! $this->campaign) {
            return;
        }

        $run = app(CampaignActionPlanAgent::class)->propose($tenantId, $this->campaign, auth()->id());

        if ($run->isFailed()) {
            Notification::make()->title('Could not draft a plan')
                ->body('The AI service did not respond. Please try again in a moment.')
                ->danger()->send();

            return;
        }

        Notification::make()->title('Action plan drafted')
            ->body('Review it below, then accept to have Autnyx create the tasks.')
            ->success()->send();
    }

    /** Human accepts a plan → Autnyx executes its own side. */
    public function acceptPlan(int $runId): void
    {
        $run = $this->guardRun($runId);
        if (! $run) {
            return;
        }

        $run = app(CampaignActionPlanAgent::class)->execute($run, auth()->id());
        $exec = $run->out('execution', []);

        Notification::make()->title('Plan accepted')
            ->body(sprintf(
                '%d action(s) created, %d investigation(s) moved into progress.',
                (int) ($exec['actions_created'] ?? 0),
                (int) ($exec['investigations_advanced'] ?? 0),
            ))
            ->success()->send();
    }

    /** Human declines a plan. */
    public function dismissPlan(int $runId): void
    {
        $run = $this->guardRun($runId);
        if (! $run) {
            return;
        }

        app(CampaignActionPlanAgent::class)->dismiss($run);
        Notification::make()->title('Plan dismissed')->send();
    }

    /** Stream the executed plan's PO draft as a CSV. */
    public function downloadPoDraft(int $runId)
    {
        $run = $this->guardRun($runId);
        if (! $run) {
            return null;
        }

        $lines    = $run->out('po_draft.lines', []);
        $currency = $run->out('po_draft.currency', 'AED');
        if (empty($lines)) {
            Notification::make()->title('No PO draft to export')->warning()->send();

            return null;
        }

        $filename = 'po-draft-' . \Illuminate\Support\Str::slug($this->campaign) . '-' . now()->format('Ymd') . '.csv';

        return response()->streamDownload(function () use ($lines, $currency) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['SKU', 'Product', 'Store', 'Suggested Qty', "Value at risk ({$currency})"]);
            foreach ($lines as $l) {
                fputcsv($out, [
                    $l['sku'] ?? '',
                    $l['sku_name'] ?? '',
                    $l['store'] ?? '',
                    $l['suggested_qty'] ?? '',
                    $l['value_at_risk'] ?? '',
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    // ── Bulk review (work a campaign as a batch, not one-by-one) ─────────────────

    /**
     * The selected investigations, hard-scoped to this tenant AND this campaign
     * (never trust the client's id list — only ids that genuinely belong to the
     * campaign in view are acted on).
     *
     * @return Collection<int,Investigation>
     */
    private function selectedInvestigations(): Collection
    {
        $tenantId = Filament::getTenant()?->id;
        if (! $tenantId || ! $this->campaign || empty($this->selected)) {
            return collect();
        }

        $rules = self::rulesForCampaign($this->campaign);
        $ids   = array_values(array_filter(array_map('intval', $this->selected)));
        if (empty($ids) || empty($rules)) {
            return collect();
        }

        return Investigation::where('tenant_id', $tenantId)
            ->whereIn('id', $ids)
            ->whereHas('anomalies', fn ($q) => $q->whereIn('rule_type', $rules))
            ->get();
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    /** Select every investigation currently listed in this campaign's drill-down. */
    public function selectAllVisible(): void
    {
        $q = $this->getQueue();
        $rows = $q['detail']['rows'] ?? [];
        $this->selected = array_values(array_filter(array_map(
            fn ($r) => $r['id'] ?? null,
            $rows
        )));
    }

    /** Bulk-snooze the selected investigations for 30 days (defer the tail together). */
    public function bulkSnooze(): void
    {
        $userId = auth()->id();
        $count  = 0;
        foreach ($this->selectedInvestigations() as $inv) {
            if (! in_array($inv->status, [Investigation::STATUS_OPEN, Investigation::STATUS_IN_PROGRESS], true)) {
                continue;
            }
            $inv->update([
                'snoozed_until' => now()->addDays(30),
                'snooze_reason' => 'bulk_snooze',
                'snooze_notes'  => 'Bulk-snoozed from the ' . $this->campaign . ' campaign.',
                'snoozed_by'    => $userId,
                'snoozed_at'    => now(),
            ]);
            $count++;
        }

        $this->clearSelection();
        Notification::make()
            ->title($count . ' investigation(s) snoozed for 30 days')
            ->success()->send();
    }

    /**
     * Bulk "start work": create one remediation Action per selected investigation
     * (campaign-typed) and advance each open one into progress — the manual
     * counterpart to the AI plan's accept→execute. Internal only; never the ERP.
     */
    public function bulkStart(): void
    {
        $userId = auth()->id();
        $type   = self::BULK_ACTION_TYPE[$this->campaign] ?? Action::TYPE_INVESTIGATE_FURTHER;
        $created = 0;
        $advanced = 0;

        foreach ($this->selectedInvestigations() as $inv) {
            $exists = Action::where('investigation_id', $inv->id)
                ->where('action_type', $type)
                ->whereIn('status', [Action::STATUS_UNASSIGNED, Action::STATUS_ASSIGNED, Action::STATUS_ACKNOWLEDGED, Action::STATUS_IN_PROGRESS])
                ->exists();

            if (! $exists) {
                $action = Action::create([
                    'investigation_id' => $inv->id,
                    'action_type'      => $type,
                    'title'            => $this->campaign . ' — ' . ($inv->primary_sku ?: 'item'),
                    'description'      => 'Created via bulk review on the ' . $this->campaign . ' campaign.',
                    'status'           => $inv->assigned_team_id ? Action::STATUS_ASSIGNED : Action::STATUS_UNASSIGNED,
                    'priority'         => $inv->priority ?? Action::PRIORITY_MEDIUM,
                    'assigned_team_id' => $inv->assigned_team_id,
                    'created_by'       => $userId,
                    'due_at'           => now()->addDays(7),
                ]);
                $created++;
                AuditLogger::actionCreated($inv, $action->id, $action->title, $userId);
            }

            if ($inv->status === Investigation::STATUS_OPEN) {
                $old = $inv->status;
                $inv->update(['status' => Investigation::STATUS_IN_PROGRESS]);
                AuditLogger::statusChanged($inv, $old, Investigation::STATUS_IN_PROGRESS, $userId);
                $advanced++;
            }
        }

        $this->clearSelection();
        Notification::make()
            ->title('Work started on the batch')
            ->body($created . ' action(s) created, ' . $advanced . ' moved into progress.')
            ->success()->send();
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
