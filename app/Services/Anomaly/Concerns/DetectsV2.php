<?php

namespace App\Services\Anomaly\Concerns;

use App\Models\Anomaly;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Store;
use App\Services\Anomaly\SeasonalityService;
use App\Services\Detection\Window;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * WP4.1 — the corrected rule set (detection.rules_v2).
 *
 * Every rule here replaces a v1 rule only when v2 is on for the tenant, so the
 * two can be compared side by side (`detection:diff`) before a switch. What
 * changes, rule family by rule family:
 *
 *   • One window definition (Window): a trailing window of N days covers N
 *     days, the window before it abuts it, rates divide by the real day count.
 *   • A clock per dataset: sales rules end on the newest sales day, inventory
 *     rules on the newest snapshot, PO rules on the newest PO event — a feed
 *     that runs ahead of another no longer empties the other's windows.
 *   • Inventory: the latest snapshot per (store, SKU), lots summed, positions
 *     that dropped out of the feed skipped; demand-based rules only judge
 *     stores present in the sales feed; stockout charges only the demand the
 *     stock on hand can't cover.
 *   • Shrink: stock and sales joined on the same store; intervals half-open;
 *     only intervals the sales feed covers.
 *   • store_outlier: each store's daily rate against its own prior rate,
 *     compared with its peers' — a real store id on every flag.
 *   • PO / supplier / receipt rules carry their own subject (po:…,
 *     supplier:…, receipt:…) so one anomaly is one PO line or one supplier,
 *     and they look back a bounded window.
 *   • price_anomaly / margin_erosion: per store, on the day's average price,
 *     promotions excluded, several deviating days required.
 *   • Returns are counted once (the returns feed when a SKU has one, the
 *     negative sales lines otherwise).
 *   • Seasonality picks year-over-year per SKU.
 *
 * @mixin \App\Services\Anomaly\AnomalyDetectionService
 */
trait DetectsV2
{
    /** v2: a baseline comparison also needs this many standard errors, whatever the tenant's sensitivity. */
    private const V2_MIN_Z = 3.0;

    /** @var array<string,Carbon> dataset => newest data day (capped at today) */
    private array $clocks = [];

    /** @var array<int,true> stores present in the sales feed over the demand window */
    private array $storesWithSales = [];

    private ?\App\Services\Detection\PromotionCalendar $promos = null;

    /** @var array<string,int> */
    private array $promoSuppressedByRule = [];

    /**
     * W10: the promotion on this SKU (and store) that overlaps [from, to], if
     * any — the swing is then the promotion, not an anomaly. Counted per rule.
     *
     * @return array{ref:string, from:string, to:string, store_id:?int}|null
     */
    private function promoExplains(int $tenantId, string $ruleType, string $sku, ?int $storeId, string $from, string $to): ?array
    {
        if (! config('detection.promo_suppression', true)) {
            return null;
        }
        if ($this->promos === null) {
            $end = $this->clock('sales');
            $this->promos = \App\Services\Detection\PromotionCalendar::load($tenantId, $end->copy()->subDays(400)->toDateString(), $end->toDateString());
        }
        $hit = $this->promos->overlapping($sku, $storeId, $from, $to);
        if ($hit !== null) {
            $this->promoSuppressedByRule[$ruleType] = ($this->promoSuppressedByRule[$ruleType] ?? 0) + 1;
        }

        return $hit;
    }

    private function clock(string $dataset): Carbon
    {
        return ($this->clocks[$dataset] ?? Carbon::today())->copy();
    }

    /** One clock per dataset — its newest day, capped at today; no data → today. */
    private function resolveClocks(int $tenantId): void
    {
        $today = Carbon::today();
        $cap = function ($d) use ($today): Carbon {
            if (! $d) {
                return $today->copy();
            }
            $c = Carbon::parse($d)->startOfDay();

            return $c->lt($today) ? $c : $today->copy();
        };

        // A PO feed is a list of open and closed orders as of the day it was
        // loaded, so the day we last learned of a PO counts as well as its dates.
        $po = DB::table('purchase_orders')->where('tenant_id', $tenantId)
            ->selectRaw('GREATEST(MAX(order_date), MAX(received_date), MAX(created_at)::date) AS d')->value('d');

        // WP6.2: current positions exist before any rule asks for them.
        app(\App\Services\Inventory\InventoryCurrentService::class)->ensure($tenantId);

        $this->clocks = [
            'sales'     => $cap(DB::table('sales_daily')->where('tenant_id', $tenantId)->max('date')),
            'inventory' => $cap(DB::table('inventory_levels')->where('tenant_id', $tenantId)->max('as_of_date')),
            'po'        => $cap($po),
        ];
    }

    /**
     * Latest position per (store, SKU) from inventory_current (WP6.2: the
     * newest snapshot day, lots/bins on that day summed). A position whose newest day is more than
     * detection.inventory_max_age_days older than the tenant's newest snapshot
     * has left the feed and is not judged.
     */
    private function primeInventorySnapshotV2(int $tenantId): void
    {
        $this->latestOnHand = [];
        $fresh = $this->clock('inventory')->subDays((int) config('detection.inventory_max_age_days', 14))->format('Y-m-d');
        [$scopeSql, $scopeBind] = $this->scopeSql('sku');

        $rows = DB::cursor(
            "SELECT store_id, sku, as_of_date, on_hand_qty AS qty, reorder_point AS rp, product_id, location
             FROM inventory_current
             WHERE tenant_id = ? AND as_of_date >= ?{$scopeSql}",
            array_merge([$tenantId, $fresh], $scopeBind)
        );

        foreach ($rows as $l) {
            $this->latestOnHand[$l->store_id . '|' . $l->sku] = [
                'qty'        => (float) $l->qty,
                'reorder'    => $l->rp !== null ? (float) $l->rp : null,
                'product_id' => $l->product_id,
                'location'   => $l->location,
                'd'          => substr((string) $l->as_of_date, 0, 10),
            ];
        }
    }

    /** Units sold per (store, SKU) over exactly $windowDays days ending on the sales clock. */
    private function primeRecentDemandV2(int $tenantId, int $windowDays): void
    {
        $this->recentDemand     = [];
        $this->demandWindowDays = max(1, $windowDays);
        $this->storesWithSales  = [];
        $w = Window::trailing($this->clock('sales'), $this->demandWindowDays);

        $w->apply(DB::table('sales_daily')->where('tenant_id', $tenantId))
            ->when($this->scope, fn ($q) => $this->scope->constrain($q))
            ->selectRaw('store_id, sku, SUM(units_sold) AS u')
            ->groupBy('store_id', 'sku')
            ->cursor()
            ->each(function ($r) {
                $this->recentDemand[$r->store_id . '|' . $r->sku] = (float) $r->u;
            });

        // Coverage is store-level and must not depend on the SKU scope.
        $w->apply(DB::table('sales_daily')->where('tenant_id', $tenantId))
            ->whereNotNull('store_id')
            ->distinct()
            ->pluck('store_id')
            ->each(function ($id) {
                $this->storesWithSales[(int) $id] = true;
            });
    }

    /** @return array<string,\Closure> rule => detector, for the rules v2 replaces */
    private function v2Rules(int $tenantId, \Closure $t): array
    {
        return [
            // W11: fresh & expiry.
            'expiry_risk'                => fn () => $this->detectExpiryRisk($tenantId, $t('expiry_risk')),
            'waste_rate'                 => fn () => $this->detectWasteRate($tenantId, $t('waste_rate')),
            // W10: the tenant's own rules (one switch in Rules settings).
            'custom_rule'                => fn () => $this->detectCustomRules($tenantId, $t('custom_rule')),
            'sales_spike'                => fn () => $this->salesSwingV2($tenantId, 'sales_spike', $t('sales_spike'), false),
            'sales_drop'                 => fn () => $this->salesSwingV2($tenantId, 'sales_drop', $t('sales_drop'), true),
            'demand_seasonality_breach'  => fn () => $this->detectDemandSeasonalityBreachV2($tenantId, $t('demand_seasonality_breach')),
            'return_rate_spike'          => fn () => $this->detectReturnRateSpikeV2($tenantId, $t('return_rate_spike')),
            'channel_mix_shift'          => fn () => $this->detectChannelMixShiftV2($tenantId, $t('channel_mix_shift')),
            'dead_stock'                 => fn () => $this->detectDeadStockV2($tenantId, $t('dead_stock')),
            'multi_location_imbalance'   => fn () => $this->detectMultiLocationImbalanceV2($tenantId),
            'reorder_point_staleness'    => fn () => $this->detectReorderPointStalenessV2($tenantId, $t('reorder_point_staleness')),
            'inventory_shrinkage'        => fn () => $this->detectInventoryShrinkageV2($tenantId, $t('inventory_shrinkage')),
            'cumulative_shrink'          => fn () => $this->detectCumulativeShrinkV2($tenantId, $t('cumulative_shrink')),
            'po_overdue'                 => fn () => $this->detectPoOverdueV2($tenantId),
            'receiving_discrepancy'      => fn () => $this->detectReceivingDiscrepancyV2($tenantId, $t('receiving_discrepancy')),
            'po_late_receipt'            => fn () => $this->detectPoLateReceiptV2($tenantId, $t('po_late_receipt')),
            'supplier_fill_rate'         => fn () => $this->detectSupplierFillRateV2($tenantId, $t('supplier_fill_rate')),
            'supplier_lead_time_drift'   => fn () => $this->detectSupplierLeadTimeDriftV2($tenantId, $t('supplier_lead_time_drift')),
            'cost_spike'                 => fn () => $this->detectCostSpikeV2($tenantId, $t('cost_spike')),
            'price_anomaly'              => fn () => $this->detectPriceAnomalyV2($tenantId, $t('price_anomaly')),
            'margin_erosion'             => fn () => $this->detectMarginErosionV2($tenantId, $t('margin_erosion')),
            'slow_moving_capital'        => fn () => $this->detectSlowMovingCapitalV2($tenantId, $t('slow_moving_capital')),
            'store_outlier'              => fn () => $this->detectStoreOutlierV2($tenantId, $t('store_outlier')),
            'duplicate_transaction_ids'  => fn () => $this->detectDuplicateTransactionIdsV2($tenantId),
        ];
    }

    // ── Demand & sales ───────────────────────────────────────────────────────

    /**
     * Recent vs the 28 days before it, from sales_daily, keyed "sku" or
     * "store_id|sku".
     *
     * @return array{0:array<string,float>,1:array<string,float>,2:Window,3:Window}
     */
    private function salesWindowsV2(int $tenantId, int $days, bool $byStore): array
    {
        $recentW = Window::trailing($this->clock('sales'), $days);
        $histW   = $recentW->before(28);
        $group   = $byStore ? 'store_id, sku' : 'sku';

        $rows = DB::table('sales_daily')
            ->where('tenant_id', $tenantId)
            ->where('date', '>=', $histW->fromDate())
            ->where('date', '<', $recentW->untilDate())
            ->when($byStore, fn ($q) => $q->whereNotNull('store_id'))
            ->when($this->scope, fn ($q) => $this->scope->constrain($q))
            ->selectRaw("{$group},
                SUM(units_sold) FILTER (WHERE date >= ?) AS recent,
                SUM(units_sold) FILTER (WHERE date < ?)  AS hist", [$recentW->fromDate(), $recentW->fromDate()])
            ->groupByRaw($group)
            ->cursor();

        $recent = $hist = [];
        foreach ($rows as $r) {
            $key = $byStore ? $r->store_id . '|' . $r->sku : (string) $r->sku;
            $recent[$key] = (float) $r->recent;
            $hist[$key]   = (float) $r->hist;
        }

        return [$recent, $hist, $recentW, $histW];
    }

    /**
     * sales_spike / sales_drop. Practical AND statistical significance: the
     * relative change must clear `pct`, and — when a baseline exists — the
     * recent mean must sit ≥ max(3, sensitivity) standard errors (σ/√n) from
     * the baseline centre. The optional store-level pass uses the same test.
     */
    private function salesSwingV2(int $tenantId, string $ruleType, array $thresholds, bool $isDrop): void
    {
        $pct        = (float) ($thresholds['pct'] ?? ($isDrop ? 30 : 50));
        $days       = (int) ($thresholds['days'] ?? 7);
        $minRevenue = (float) ($thresholds['min_revenue'] ?? self::DEFAULT_MIN_REVENUE);

        $this->salesSwingPassV2($tenantId, $ruleType, $days, $pct, $minRevenue, $isDrop, false, 0.0);

        if ($thresholds['store_level'] ?? false) {
            $this->salesSwingPassV2($tenantId, $ruleType, $days, $pct, $minRevenue, $isDrop, true,
                (float) ($thresholds['store_min_units'] ?? 20));
        }
    }

    private function salesSwingPassV2(int $tenantId, string $ruleType, int $days, float $pct, float $minRevenue, bool $isDrop, bool $byStore, float $minUnits): void
    {
        [$recent, $hist, $recentW, $histW] = $this->salesWindowsV2($tenantId, $days, $byStore);

        // Baselines at this pass's own level only — a store is never measured
        // against the chain's daily rate. One query, not one per SKU.
        $baselines = \App\Models\SkuBaseline::where('tenant_id', $tenantId)
            ->where('rule_type', $ruleType)->where('metric', 'daily_sales_qty')
            ->when($byStore, fn ($q) => $q->whereNotNull('store_id'), fn ($q) => $q->whereNull('store_id'))
            ->get()
            ->keyBy(fn ($b) => $byStore ? $b->store_id . '|' . $b->sku : (string) $b->sku);

        foreach ($hist as $key => $histQty) {
            if ($histQty <= 0) {
                continue;
            }
            [$storeId, $sku] = $byStore ? explode('|', $key, 2) : [null, $key];
            $storeId = $storeId !== null ? (int) $storeId : null;

            $recentRate   = ($recent[$key] ?? 0.0) / $recentW->days();
            $expectedRate = $histQty / $histW->days();
            $z = null;

            $baseline = $baselines->get($key);
            if ($baseline && $baseline->baseline_stddev > 0) {
                $se = $baseline->baseline_stddev / sqrt($recentW->days());
                $z  = ($recentRate - $baseline->baseline_mean) / max(0.0001, $se);
                $z  = $isDrop ? -$z : $z;
                if ($z < max(self::V2_MIN_Z, (float) $baseline->sensitivity_multiplier)) {
                    continue;
                }
                $expectedRate = (float) $baseline->baseline_mean;
            }
            if ($expectedRate <= 0) {
                continue;
            }

            $change = ($isDrop ? $expectedRate - $recentRate : $recentRate - $expectedRate) / $expectedRate * 100;
            if ($change < $pct) {
                continue;
            }
            $units = abs($recentRate - $expectedRate) * $recentW->days();
            if ($units < $minUnits) {
                continue;
            }

            $price  = $this->unitPrice($sku);
            $impact = $units * $price;
            if ($price > 0 && $impact < $minRevenue) {
                continue;
            }
            $severity = $price > 0 ? $this->severityFromImpact($impact)
                : ($isDrop ? Anomaly::SEVERITY_MEDIUM : Anomaly::SEVERITY_LOW);

            // W10: a spike during a promotion, or a drop against a promo-inflated
            // baseline / in the dip after one, is the promotion — not an anomaly.
            if ($this->promoExplains($tenantId, $ruleType, (string) $sku, $storeId,
                $isDrop ? $histW->fromDate() : $recentW->fromDate(), $recentW->lastDate())) {
                continue;
            }

            $where = $storeId !== null ? " at store {$storeId}" : '';
            $this->flag($tenantId, $ruleType, $severity, $sku, $storeId, null,
                "SKU {$sku}{$where} sold " . round($recentRate, 1) . " units/day over the {$recentW->days()} days to "
                . $recentW->lastDate() . ' — ' . round($change) . '% ' . ($isDrop ? 'below' : 'above')
                . ' its expected ' . round($expectedRate, 1) . ' units/day'
                . ($z !== null ? ' (' . round($z, 1) . ' standard errors)' : '') . '.',
                ['recent_daily' => round($recentRate, 2), 'expected_daily' => round($expectedRate, 2),
                 'change_pct' => round($change, 1), 'z_score' => $z !== null ? round($z, 2) : null,
                 'days' => $recentW->days(), 'window' => [$recentW->fromDate(), $recentW->lastDate()],
                 'units' => round($units, 1), 'revenue_impact' => round($impact, 2)]
                + ($storeId !== null ? ['scope' => 'store', 'store_id' => $storeId] : [])
            );
        }
        // A SKU with no history has no expectation — a launch, not a spike.
    }

    /** Year-over-year for SKUs that sold a year ago; the calendar-adjusted fallback for the rest. */
    private function detectDemandSeasonalityBreachV2(int $tenantId, array $thresholds): void
    {
        $pct        = (float) ($thresholds['pct'] ?? 40);
        $minRevenue = (float) ($thresholds['min_revenue'] ?? self::TREND_MIN_REVENUE);

        $current = Window::trailing($this->clock('sales'), 30);
        $prior   = $current->yearEarlier();
        $sum = fn (Window $w) => $w->apply(DB::table('sales_daily')->where('tenant_id', $tenantId))
            ->selectRaw('sku, SUM(units_sold) AS u')->groupBy('sku')->pluck('u', 'sku')->map(fn ($v) => (float) $v);

        $cur = $sum($current);
        $old = $sum($prior)->filter(fn ($v) => $v > 0);
        $productIds = Product::where('tenant_id', $tenantId)->pluck('id', 'sku');

        foreach ($cur as $sku => $currentQty) {
            if (! $old->has($sku)) {
                continue; // no year-ago sales for this SKU → the fallback judges it
            }
            $priorQty  = $old[$sku];
            $changePct = abs($currentQty - $priorQty) / $priorQty * 100;
            if ($changePct < $pct) {
                continue;
            }
            $price  = $this->unitPrice($sku);
            $impact = abs($currentQty - $priorQty) * $price;
            if ($price > 0 && $impact < $minRevenue) {
                continue;
            }
            $direction = $currentQty > $priorQty ? 'above' : 'below';
            // W10: a promotion this year (above) or in last year's window (below) explains it.
            $promoW = $direction === 'above' ? $current : $prior;
            if ($this->promoExplains($tenantId, 'demand_seasonality_breach', (string) $sku, null, $promoW->fromDate(), $promoW->lastDate())) {
                continue;
            }
            $this->flag($tenantId, 'demand_seasonality_breach', 'medium', $sku, null, $productIds[$sku] ?? null,
                "SKU {$sku} sold " . round($currentQty) . " units in the 30 days to {$current->lastDate()} — "
                . round($changePct) . "% {$direction} the same 30 days last year (" . round($priorQty) . ' units).',
                ['mode' => 'yoy', 'current_qty' => $currentQty, 'prior_year_qty' => $priorQty,
                 'change_pct' => round($changePct, 1), 'direction' => $direction, 'revenue_impact' => round($impact, 2)]
            );
        }

        // Fallback for SKUs with no year-ago sales: last 7 days vs the calendar-
        // adjusted expectation from the 28 days before.
        $seasonality = new SeasonalityService();
        $dowFactors  = $seasonality->dayOfWeekFactors($tenantId, 90);
        $recentW     = Window::trailing($this->clock('sales'), 7);
        $baseW       = $recentW->before(28);
        $recentDates = [];
        for ($d = $recentW->from->copy(); $d->lt($recentW->until); $d->addDay()) {
            $recentDates[] = $d->format('Y-m-d');
        }
        $recent = $sum($recentW);

        foreach ($sum($baseW) as $sku => $baseUnits) {
            if ($old->has($sku)) {
                continue;
            }
            $baselineDaily = $baseUnits / $baseW->days();
            if ($baselineDaily <= 0) {
                continue;
            }
            $expected = $seasonality->expectedUnits($baselineDaily, $dowFactors, $recentDates);
            if ($expected <= 0) {
                continue;
            }
            $actual    = (float) ($recent[$sku] ?? 0);
            $deviation = abs($actual - $expected) / $expected * 100;
            if ($deviation < $pct) {
                continue;
            }
            $price  = $this->unitPrice($sku);
            $impact = abs($actual - $expected) * $price;
            if ($price > 0 && $impact < $minRevenue) {
                continue;
            }
            $direction = $actual > $expected ? 'above' : 'below';
            if ($this->promoExplains($tenantId, 'demand_seasonality_breach', (string) $sku, null,
                $direction === 'above' ? $recentW->fromDate() : $baseW->fromDate(), $recentW->lastDate())) {
                continue;
            }
            $this->flag($tenantId, 'demand_seasonality_breach', 'medium', $sku, null, $productIds[$sku] ?? null,
                "SKU {$sku} sold " . round($actual) . " units in the 7 days to {$recentW->lastDate()} — "
                . round($deviation) . "% {$direction} its calendar-adjusted expectation of " . round($expected) . ' units.',
                ['mode' => 'seasonal_adjusted', 'actual' => round($actual, 1), 'expected' => round($expected, 1),
                 'deviation_pct' => round($deviation, 1), 'direction' => $direction, 'revenue_impact' => round($impact, 2)]
            );
        }
    }

    /**
     * Return rate = units returned ÷ units sold. A SKU's returns come from the
     * returns feed when it has any there, otherwise from negative sales lines —
     * never both, so a return recorded in each isn't counted twice.
     */
    private function detectReturnRateSpikeV2(int $tenantId, array $thresholds): void
    {
        $pct = (float) ($thresholds['pct'] ?? 15);
        $w   = Window::trailing($this->clock('sales'), (int) ($thresholds['days'] ?? 30));
        [$range, $bind] = $w->sql('date');

        $sales = collect(DB::select(
            "SELECT sku, SUM(quantity) FILTER (WHERE quantity > 0) AS sold, -SUM(quantity) FILTER (WHERE quantity < 0) AS neg
             FROM sales_transactions WHERE tenant_id = ? AND {$range} GROUP BY sku",
            array_merge([$tenantId], $bind)
        ))->keyBy('sku');
        $feed = collect(DB::select(
            "SELECT sku, SUM(ABS(quantity)) AS q FROM sales_returns WHERE tenant_id = ? AND {$range} GROUP BY sku",
            array_merge([$tenantId], $bind)
        ))->pluck('q', 'sku');

        foreach ($sales as $sku => $r) {
            $sold = (float) $r->sold;
            if ($sold <= 0) {
                continue;
            }
            $fromFeed = $feed->has($sku);
            $returns  = $fromFeed ? (float) $feed[$sku] : (float) $r->neg;
            if ($returns <= 0) {
                continue;
            }
            $rate = $returns / $sold * 100;
            if ($rate < $pct) {
                continue;
            }
            $impact = $returns * $this->unitPrice($sku);
            $this->flag($tenantId, 'return_rate_spike', 'medium', $sku, null, null,
                "SKU {$sku} had " . round($rate, 1) . "% of units returned in the {$w->days()} days to {$w->lastDate()} "
                . '(' . round($returns) . ' returned of ' . round($sold) . ' sold'
                . ($impact > 0 ? ', ' . $this->money($impact) : '') . ').',
                ['sales_qty' => $sold, 'returns_qty' => $returns, 'return_rate_pct' => round($rate, 1),
                 'returns_source' => $fromFeed ? 'returns_feed' : 'negative_sales_lines',
                 'days' => $w->days(), 'revenue_impact' => round($impact, 2)]
            );
        }
    }

    /** A store's share of chain sales, this window vs the one before — keyed on the store. */
    private function detectChannelMixShiftV2(int $tenantId, array $thresholds): void
    {
        $pct      = (float) ($thresholds['pct'] ?? 25);
        $minUnits = (float) ($thresholds['min_units'] ?? 200);
        $recentW  = Window::trailing($this->clock('sales'), (int) ($thresholds['days'] ?? 30));
        $priorW   = $recentW->before($recentW->days());

        $rows = DB::table('sales_daily')->where('tenant_id', $tenantId)->whereNotNull('store_id')
            ->where('date', '>=', $priorW->fromDate())->where('date', '<', $recentW->untilDate())
            ->selectRaw('store_id, SUM(units_sold) FILTER (WHERE date >= ?) AS r, SUM(units_sold) FILTER (WHERE date < ?) AS p',
                [$recentW->fromDate(), $recentW->fromDate()])
            ->groupBy('store_id')->get();

        $recentTotal = (float) $rows->sum('r');
        $priorTotal  = (float) $rows->sum('p');
        if ($recentTotal <= 0 || $priorTotal <= 0) {
            return;
        }
        $names = Store::where('tenant_id', $tenantId)->pluck('name', 'id');

        foreach ($rows as $row) {
            $r = (float) $row->r;
            $p = (float) $row->p;
            if ($p <= 0 || ($r + $p) < $minUnits) {
                continue;
            }
            $recentShare = $r / $recentTotal * 100;
            $priorShare  = $p / $priorTotal * 100;
            $shift       = $recentShare - $priorShare;
            $relChange   = abs($shift) / max(0.0001, $priorShare) * 100;
            if ($relChange < $pct || abs($shift) < 0.5) {
                continue;
            }
            $name = $names[$row->store_id] ?? "store {$row->store_id}";
            $direction = $shift > 0 ? 'gained' : 'lost';
            $this->flag($tenantId, 'channel_mix_shift', 'medium', null, (int) $row->store_id, null,
                "'{$name}' {$direction} " . round($relChange) . '% of its share of chain sales (now '
                . round($recentShare, 1) . '% vs ' . round($priorShare, 1) . "% in the prior {$priorW->days()} days).",
                ['location' => $name, 'recent_share_pct' => round($recentShare, 1), 'prior_share_pct' => round($priorShare, 1),
                 'shift_pct' => round($shift, 1), 'relative_change_pct' => round($relChange, 1), 'days' => $recentW->days()]
            );
        }
    }

    // ── Inventory ────────────────────────────────────────────────────────────

    /**
     * Stock on hand (current positions) for a SKU that sold nowhere in the
     * window. WP6.3: aggregated in SQL over inventory_current — only the dead
     * SKUs come back, whatever the number of positions.
     */
    private function detectDeadStockV2(int $tenantId, array $thresholds): void
    {
        if (! $this->hasStoresWithSales($tenantId)) {
            return; // no sales feed in the window — silence is not evidence of dead stock
        }
        $w = Window::trailing($this->clock('sales'), (int) ($thresholds['days'] ?? 30));

        foreach ($this->unsoldHoldings($tenantId, $w) as $h) {
            $value = (float) $h->qty * $this->unitCost((string) $h->sku);
            $this->flag($tenantId, 'dead_stock', 'low', (string) $h->sku, null, $h->product_id,
                "SKU {$h->sku} has " . round((float) $h->qty) . " units on hand across {$h->stores} store(s)"
                . ($value > 0 ? ' (' . $this->money($value) . ')' : '') . " and no sales in the {$w->days()} days to {$w->lastDate()}.",
                ['on_hand_qty' => (float) $h->qty, 'stores' => (int) $h->stores, 'days_without_sales' => $w->days(),
                 'inventory_value' => round($value, 2)]
            );
        }
    }

    /**
     * SKUs with stock on hand in fresh positions and no unit sold anywhere in
     * the window: sku, qty, stores, product_id.
     *
     * @return iterable<object>
     */
    private function unsoldHoldings(int $tenantId, Window $w): iterable
    {
        [$scopeSql, $scopeBind] = $this->scopeSql('c.sku');

        return DB::cursor(
            "SELECT c.sku, SUM(c.on_hand_qty) AS qty, COUNT(*) AS stores, MAX(c.product_id) AS product_id
               FROM inventory_current c
              WHERE c.tenant_id = ? AND c.on_hand_qty > 0 AND c.as_of_date >= ?{$scopeSql}
                AND NOT EXISTS (SELECT 1 FROM sales_daily s
                                 WHERE s.tenant_id = c.tenant_id AND s.sku = c.sku
                                   AND s.date >= ? AND s.date < ? AND s.units_sold > 0)
              GROUP BY c.sku
              ORDER BY c.sku",
            array_merge([$tenantId, $this->inventoryFreshFrom()], $scopeBind, [$w->fromDate(), $w->untilDate()])
        );
    }

    private function inventoryFreshFrom(): string
    {
        return $this->clock('inventory')->subDays((int) config('detection.inventory_max_age_days', 14))->format('Y-m-d');
    }

    /** Is any store in the sales feed over the demand window? (A cheap SQL check when the maps are not primed.) */
    private function hasStoresWithSales(int $tenantId): bool
    {
        if ($this->storesWithSales !== []) {
            return true;
        }
        $w = Window::trailing($this->clock('sales'), max(1, (int) ($this->demandWindowDays ?: 30)));

        return $w->apply(DB::table('sales_daily')->where('tenant_id', $tenantId))->whereNotNull('store_id')->exists();
    }

    /**
     * Out at one store, more than twice its reorder point at another — current
     * positions only; a missing reorder point falls back to the derived one.
     * WP6.3: one SQL pass over inventory_current, only imbalanced SKUs return.
     */
    private function detectMultiLocationImbalanceV2(int $tenantId): void
    {
        [$scopeSql, $scopeBind] = $this->scopeSql('c.sku');
        $hasRep = DB::getSchemaBuilder()->hasTable('sku_replenishment');
        $rpSql  = $hasRep ? 'COALESCE(c.reorder_point, CASE WHEN r.reorder_point > 0 THEN r.reorder_point END)' : 'c.reorder_point';
        $repJoin = $hasRep ? 'LEFT JOIN sku_replenishment r ON r.tenant_id = c.tenant_id AND r.store_id = c.store_id AND r.sku = c.sku' : '';

        $rows = DB::cursor(
            "WITH p AS (
                SELECT c.sku, c.location, c.on_hand_qty AS q, c.product_id, {$rpSql} AS rp
                  FROM inventory_current c {$repJoin}
                 WHERE c.tenant_id = ? AND c.as_of_date >= ?{$scopeSql}
             ), f AS (
                SELECT p.*, (q <= 0 OR (rp IS NOT NULL AND q <= rp)) AS is_out, (rp > 0 AND q > rp * 2) AS is_over FROM p
             )
             SELECT sku, MAX(product_id) AS product_id,
                    (ARRAY_AGG(location ORDER BY q ASC)  FILTER (WHERE is_out))[1]  AS out_loc,
                    MIN(q) FILTER (WHERE is_out)  AS out_qty,
                    (ARRAY_AGG(location ORDER BY q DESC) FILTER (WHERE is_over))[1] AS over_loc,
                    MAX(q) FILTER (WHERE is_over) AS over_qty
               FROM f
              GROUP BY sku
             HAVING COUNT(*) >= 2 AND BOOL_OR(is_out) AND BOOL_OR(is_over)
              ORDER BY sku",
            array_merge([$tenantId, $this->inventoryFreshFrom()], $scopeBind)
        );

        foreach ($rows as $a) {
            $this->flag($tenantId, 'multi_location_imbalance', 'medium', (string) $a->sku, null, $a->product_id,
                "SKU {$a->sku} is out at '{$a->out_loc}' while '{$a->over_loc}' holds " . round((float) $a->over_qty)
                . ' units — consider rebalancing.',
                ['stocked_out_location' => $a->out_loc, 'overstocked_location' => $a->over_loc,
                 'surplus_qty' => round((float) $a->over_qty), 'stocked_out_qty' => (float) $a->out_qty]
            );
        }
    }

    /**
     * A reorder point unchanged for `days` on a position that is still in the
     * feed and still selling. "Unchanged since" is the first snapshot of the
     * current unbroken run of that value — a short history is never called stale.
     */
    private function detectReorderPointStalenessV2(int $tenantId, array $thresholds): void
    {
        $days  = (int) ($thresholds['days'] ?? 90);
        $clock = $this->clock('inventory');
        $staleBefore = $clock->copy()->subDays($days)->format('Y-m-d');
        [$scopeSql, $scopeBind] = $this->scopeSql('c.sku');
        $demand = Window::trailing($this->clock('sales'), max(1, (int) ($this->demandWindowDays ?: 30)));

        // WP6.3: from inventory_current (reorder_point_since is maintained per
        // load), selling = sold at that store in the demand window — no history
        // scan, no position map. Only stale positions come back.
        $base = "FROM inventory_current c
                 WHERE c.tenant_id = ? AND c.reorder_point > 0 AND c.as_of_date >= ?{$scopeSql}
                   AND EXISTS (SELECT 1 FROM sales_daily s WHERE s.tenant_id = c.tenant_id AND s.store_id = c.store_id
                                  AND s.sku = c.sku AND s.date >= ? AND s.date < ? AND s.units_sold > 0)";
        $bind = array_merge([$tenantId, $this->inventoryFreshFrom()], $scopeBind, [$demand->fromDate(), $demand->untilDate()]);

        $counts = DB::selectOne(
            "SELECT COUNT(*) AS selling, COUNT(*) FILTER (WHERE c.reorder_point_since <= ?) AS stale {$base}",
            array_merge([$staleBefore], $bind)
        );
        $selling = (int) $counts->selling;
        $stale   = (int) $counts->stale;

        $select = "SELECT c.store_id, c.sku, c.reorder_point AS rp, c.product_id, c.location,
                          TO_CHAR(c.as_of_date, 'YYYY-MM-DD') AS latest_date, TO_CHAR(c.reorder_point_since, 'YYYY-MM-DD') AS since
                   {$base} AND c.reorder_point_since <= ? ORDER BY c.store_id, c.sku";

        // When most reorder points are stale it is ONE process finding — reorder
        // points aren't being maintained — not thousands of items for the queue.
        $maxItems = (int) ($thresholds['max_items'] ?? 50);
        if ($stale > $maxItems && $stale >= 0.2 * max(1, $selling)) {
            $examples = DB::select($select . ' LIMIT 10', array_merge($bind, [$staleBefore]));
            $this->flag($tenantId, 'reorder_point_staleness', 'medium', null, null, null,
                "{$stale} of {$selling} selling positions have kept the same reorder point for {$days}+ days — "
                . 'reorder points look unmaintained. Review how they are set rather than item by item.',
                ['positions_stale' => $stale, 'positions_selling' => $selling, 'days' => $days,
                 'examples' => array_map(fn ($r) => ['store_id' => (int) $r->store_id, 'sku' => $r->sku, 'since' => $r->since], $examples)],
                'reorder_points'
            );

            return;
        }

        foreach (DB::cursor($select, array_merge($bind, [$staleBefore])) as $r) {
            $daysStale = (int) Carbon::parse($r->since)->diffInDays($clock, absolute: true);
            $this->flag($tenantId, 'reorder_point_staleness', 'low', $r->sku, (int) $r->store_id, $r->product_id,
                "SKU {$r->sku} has kept a reorder point of " . round((float) $r->rp) . " since {$r->since} ({$daysStale} days) "
                . 'while it keeps selling — check it still matches demand.',
                ['reorder_point' => (float) $r->rp, 'unchanged_since' => $r->since, 'as_of_date' => $r->latest_date,
                 'days_stale' => $daysStale, 'location' => $r->location]
            );
        }
    }

    /**
     * Positions (lots summed) per store and day. The sales that explain a
     * drop are that store's sales after the earlier snapshot up to and
     * including the later one. An interval counts only when the sales feed
     * covers it: the later snapshot is on or before the sales clock and the
     * store reported sales in it.
     */
    private function shrinkPositionsCte(): string
    {
        return "pos AS (
                SELECT store_id, sku, as_of_date, SUM(on_hand_qty) AS qty,
                       MAX(product_id) AS product_id, MAX(location) AS location
                FROM inventory_levels
                WHERE tenant_id = ? AND store_id IS NOT NULL AND as_of_date >= ?%s
                GROUP BY store_id, sku, as_of_date
            )";
    }

    private function detectInventoryShrinkageV2(int $tenantId, array $thresholds): void
    {
        $pct      = (float) ($thresholds['pct'] ?? 20);
        $minValue = (float) ($thresholds['min_value'] ?? self::DEFAULT_MIN_REVENUE);
        $fresh    = $this->inventoryFreshFrom();
        $salesTo  = $this->clock('sales')->format('Y-m-d');
        [$scopeSql, $scopeBind] = $this->scopeSql('c.sku');

        // WP6.3: the latest interval of each position is held on inventory_current
        // (snapshot and the one before it) — no window over the whole history.
        $rows = DB::select(
            "WITH drops AS (
                SELECT c.store_id, c.sku, c.location, c.product_id, c.on_hand_qty AS qty, c.prev_on_hand_qty AS prev_qty,
                       c.prev_as_of_date AS prev_date, c.as_of_date
                  FROM inventory_current c
                 WHERE c.tenant_id = ? AND c.prev_on_hand_qty > 0 AND c.on_hand_qty < c.prev_on_hand_qty
                   AND c.as_of_date >= ? AND c.as_of_date <= ?{$scopeSql}
             )
             SELECT d.store_id, d.sku, d.location, d.product_id, d.qty AS latest_qty, d.prev_qty,
                    TO_CHAR(d.prev_date, 'YYYY-MM-DD') AS prev_date, TO_CHAR(d.as_of_date, 'YYYY-MM-DD') AS latest_date,
                    COALESCE(sd.q, 0) AS sales, d.prev_qty - COALESCE(sd.q, 0) - d.qty AS unexplained
             FROM drops d
             LEFT JOIN LATERAL (
                SELECT SUM(s.units_sold) AS q FROM sales_daily s
                WHERE s.tenant_id = ? AND s.store_id = d.store_id AND s.sku = d.sku
                  AND s.date > d.prev_date AND s.date <= d.as_of_date
             ) sd ON TRUE
             WHERE d.prev_qty - COALESCE(sd.q, 0) - d.qty > 0
               AND (d.prev_qty - COALESCE(sd.q, 0) - d.qty) / d.prev_qty * 100 >= ?
               AND EXISTS (SELECT 1 FROM sales_daily c WHERE c.tenant_id = ? AND c.store_id = d.store_id
                           AND c.date > d.prev_date AND c.date <= d.as_of_date)",
            array_merge([$tenantId, $fresh, $salesTo], $scopeBind, [$tenantId, $pct, $tenantId])
        );

        foreach ($rows as $row) {
            $unexplained = (float) $row->unexplained;
            $shrinkPct   = $unexplained / (float) $row->prev_qty * 100;
            $cost  = $this->unitCost($row->sku);
            $value = $unexplained * $cost;
            if ($cost > 0 && $value < $minValue) {
                continue;
            }
            $severity = $cost > 0 ? $this->severityFromImpact($value) : Anomaly::SEVERITY_HIGH;

            $this->flag($tenantId, 'inventory_shrinkage', $severity, $row->sku, (int) $row->store_id, $row->product_id,
                "SKU {$row->sku} at '{$row->location}' lost " . round($unexplained) . ' units (' . $this->money($value)
                . ", " . round($shrinkPct) . "%) that its own sales don't explain between {$row->prev_date} and {$row->latest_date}.",
                ['location' => $row->location, 'prev_qty' => (float) $row->prev_qty, 'latest_qty' => (float) $row->latest_qty,
                 'sales_in_period' => (float) $row->sales, 'unexplained' => round($unexplained, 2),
                 'shrinkage_pct' => round($shrinkPct, 1), 'inventory_value' => round($value, 2), 'revenue_impact' => round($value, 2)]
            );
        }
    }

    private function detectCumulativeShrinkV2(int $tenantId, array $thresholds): void
    {
        $minValue     = (float) ($thresholds['min_value'] ?? 1000);
        $minIntervals = (int) ($thresholds['min_intervals'] ?? 3);
        $lookback     = Window::trailing($this->clock('inventory'), (int) ($thresholds['lookback_days'] ?? 90));
        $salesTo      = $this->clock('sales')->format('Y-m-d');
        [$scopeSql, $scopeBind] = $this->scopeSql('sku');

        $rows = DB::select(
            'WITH ' . sprintf($this->shrinkPositionsCte(), $scopeSql) . ",
             ivals AS (
                SELECT pos.*, LAG(qty) OVER w AS prev_qty, LAG(as_of_date) OVER w AS prev_date
                FROM pos WINDOW w AS (PARTITION BY store_id, sku ORDER BY as_of_date ASC)
             ),
             drops AS (
                SELECT * FROM ivals
                WHERE prev_qty IS NOT NULL AND prev_qty > qty AND as_of_date >= ? AND as_of_date < ? AND as_of_date <= ?
                  AND EXISTS (SELECT 1 FROM sales_daily c WHERE c.tenant_id = ? AND c.store_id = ivals.store_id
                              AND c.date > ivals.prev_date AND c.date <= ivals.as_of_date)
             )
             SELECT d.store_id, d.sku, MAX(d.location) AS location, MAX(d.product_id) AS product_id,
                    SUM(GREATEST(0, d.prev_qty - COALESCE(s.q, 0) - d.qty)) AS cum_unexplained,
                    COUNT(*) FILTER (WHERE d.prev_qty - COALESCE(s.q, 0) - d.qty > 0) AS loss_intervals
             FROM drops d
             LEFT JOIN LATERAL (
                SELECT SUM(sd.units_sold) AS q FROM sales_daily sd
                WHERE sd.tenant_id = ? AND sd.store_id = d.store_id AND sd.sku = d.sku
                  AND sd.date > d.prev_date AND sd.date <= d.as_of_date
             ) s ON TRUE
             GROUP BY d.store_id, d.sku
             HAVING COUNT(*) FILTER (WHERE d.prev_qty - COALESCE(s.q, 0) - d.qty > 0) >= ?",
            // WP6.3: only snapshots from shortly before the lookback (the first
            // interval needs the one before it), not the whole history.
            array_merge([$tenantId, Carbon::parse($lookback->fromDate())->subDays(45)->format('Y-m-d')], $scopeBind,
                [$lookback->fromDate(), $lookback->untilDate(), $salesTo, $tenantId, $tenantId, $minIntervals])
        );

        foreach ($rows as $r) {
            $cum = (float) $r->cum_unexplained;
            if ($cum <= 0) {
                continue;
            }
            $cost  = $this->unitCost($r->sku);
            $value = $cum * $cost;
            if ($cost > 0 && $value < $minValue) {
                continue;
            }
            $severity = $cost > 0 ? $this->severityFromImpact($value) : Anomaly::SEVERITY_HIGH;

            $this->flag($tenantId, 'cumulative_shrink', $severity, $r->sku, (int) $r->store_id, $r->product_id,
                "SKU {$r->sku} at '{$r->location}' lost " . round($cum) . ' units (' . $this->money($value) . ') that its sales '
                . 'don\'t explain across ' . (int) $r->loss_intervals . " separate declines in the {$lookback->days()} days to "
                . $lookback->lastDate() . ', masked by restocking in between.',
                ['location' => $r->location, 'cumulative_units' => round($cum, 1), 'loss_intervals' => (int) $r->loss_intervals,
                 'lookback_days' => $lookback->days(), 'inventory_value' => round($value, 2), 'revenue_impact' => round($value, 2)]
            );
        }
    }

    // ── Purchase orders & suppliers ──────────────────────────────────────────

    /** "po:<number>" — one anomaly per PO line (with the SKU and store). */
    private function poSubject(?string $poNumber): ?string
    {
        $n = trim((string) $poNumber);

        return $n === '' ? null : 'po:' . $n;
    }

    /** "supplier:<id>" when the supplier is known, else its normalised name. */
    private function supplierSubject(?int $supplierId, ?string $name): string
    {
        return $supplierId !== null ? 'supplier:' . $supplierId : 'supplier:' . mb_strtolower(trim((string) $name));
    }

    private function openPoStatus($q)
    {
        return $q->where(fn ($s) => $s->whereNull('status')
            ->orWhereNotIn(DB::raw('LOWER(status)'), ['cancelled', 'canceled', 'closed', 'received']));
    }

    private function detectPoOverdueV2(int $tenantId): void
    {
        $clock = $this->clock('po');

        $this->openPoStatus(PurchaseOrder::where('tenant_id', $tenantId))
            ->whereNotNull('expected_date')
            ->where('expected_date', '<', $clock->format('Y-m-d'))
            ->whereNull('received_date')
            ->whereRaw('COALESCE(qty_received, 0) < qty_ordered')
            ->select(['po_number', 'supplier', 'supplier_id', 'sku', 'store_id', 'product_id', 'qty_ordered', 'qty_received', 'expected_date'])
            ->cursor()
            ->each(function ($po) use ($tenantId, $clock) {
                $daysOverdue = (int) Carbon::parse($po->expected_date)->diffInDays($clock, absolute: true);
                $open  = (float) $po->qty_ordered - (float) ($po->qty_received ?? 0);
                $value = $open * $this->unitCost($po->sku);
                $this->flag($tenantId, 'po_overdue', 'medium', $po->sku, $po->store_id, $po->product_id,
                    "PO #{$po->po_number} from {$po->supplier} (SKU {$po->sku}) is {$daysOverdue} day(s) overdue — "
                    . 'expected ' . Carbon::parse($po->expected_date)->format('Y-m-d') . ', ' . round($open) . ' units still open'
                    . ($value > 0 ? ' (' . $this->money($value) . ')' : '') . '.',
                    ['po_number' => $po->po_number, 'supplier' => $po->supplier, 'days_overdue' => $daysOverdue,
                     'qty_ordered' => (float) $po->qty_ordered, 'qty_received' => (float) ($po->qty_received ?? 0),
                     'goods_value' => round($value, 2)],
                    $this->poSubject($po->po_number)
                );
            });
    }

    private function detectReceivingDiscrepancyV2(int $tenantId, array $thresholds): void
    {
        $threshold = 1 - ((float) ($thresholds['pct'] ?? 20) / 100);
        $minValue  = (float) ($thresholds['min_value'] ?? self::DEFAULT_MIN_REVENUE);
        $w = Window::trailing($this->clock('po'), (int) ($thresholds['lookback_days'] ?? 90));

        $w->apply(PurchaseOrder::where('tenant_id', $tenantId), 'received_date')
            ->where('qty_ordered', '>', 0)
            ->whereRaw('COALESCE(qty_received, 0) < qty_ordered * ?', [$threshold])
            ->select(['po_number', 'supplier', 'sku', 'store_id', 'qty_ordered', 'qty_received', 'product_id', 'received_date'])
            ->cursor()
            ->each(function ($po) use ($tenantId, $minValue) {
                $ordered   = (float) $po->qty_ordered;
                $received  = (float) ($po->qty_received ?? 0);
                $cost      = $this->unitCost($po->sku);
                $value     = ($ordered - $received) * $cost;
                if ($cost > 0 && $value < $minValue) {
                    return;
                }
                $receivedPct = round($received / $ordered * 100);
                $this->flag($tenantId, 'receiving_discrepancy', $cost > 0 ? $this->severityFromImpact($value) : Anomaly::SEVERITY_MEDIUM,
                    $po->sku, $po->store_id, $po->product_id,
                    "PO #{$po->po_number} from {$po->supplier} (SKU {$po->sku}) was received on "
                    . Carbon::parse($po->received_date)->format('Y-m-d') . " with only {$receivedPct}% of the order ("
                    . round($received) . ' of ' . round($ordered) . ' units, ' . $this->money($value) . ' short).',
                    ['po_number' => $po->po_number, 'supplier' => $po->supplier, 'qty_ordered' => $ordered, 'qty_received' => $received,
                     'received_pct' => $receivedPct, 'goods_value' => round($value, 2)],
                    $this->poSubject($po->po_number)
                );
            });
    }

    private function detectPoLateReceiptV2(int $tenantId, array $thresholds): void
    {
        $minLate = (int) ($thresholds['days'] ?? 7);
        $w = Window::trailing($this->clock('po'), (int) ($thresholds['lookback_days'] ?? 90));

        $w->apply(PurchaseOrder::where('tenant_id', $tenantId), 'received_date')
            ->whereNotNull('expected_date')
            ->whereRaw('(received_date::date - expected_date::date) >= ?', [$minLate])
            ->select(['po_number', 'supplier', 'sku', 'store_id', 'qty_received', 'expected_date', 'received_date', 'product_id'])
            ->cursor()
            ->each(function ($po) use ($tenantId) {
                $expected = Carbon::parse($po->expected_date);
                $received = Carbon::parse($po->received_date);
                $daysLate = (int) $expected->diffInDays($received, absolute: true);
                $value    = (float) $po->qty_received * $this->unitCost($po->sku);
                $severity = $daysLate >= 30 ? Anomaly::SEVERITY_HIGH : ($daysLate >= 14 ? Anomaly::SEVERITY_MEDIUM : Anomaly::SEVERITY_LOW);
                if ($value >= 10000 && $severity === Anomaly::SEVERITY_LOW) {
                    $severity = Anomaly::SEVERITY_MEDIUM;
                }
                $this->flag($tenantId, 'po_late_receipt', $severity, $po->sku, $po->store_id, $po->product_id,
                    "PO #{$po->po_number} from {$po->supplier} (SKU {$po->sku}) arrived {$daysLate} day(s) late "
                    . "(expected {$expected->format('Y-m-d')}, received {$received->format('Y-m-d')}).",
                    ['po_number' => $po->po_number, 'supplier' => $po->supplier, 'days_late' => $daysLate,
                     'expected_date' => $expected->format('Y-m-d'), 'received_date' => $received->format('Y-m-d'),
                     'qty_received' => (float) $po->qty_received, 'goods_value' => round($value, 2)],
                    $this->poSubject($po->po_number)
                );
            });
    }

    private function detectSupplierFillRateV2(int $tenantId, array $thresholds): void
    {
        $fillFrac = (float) ($thresholds['pct'] ?? 85) / 100;
        $minPos   = (int) ($thresholds['min_pos'] ?? 3);
        $minValue = (float) ($thresholds['min_value'] ?? 200);
        $w = Window::trailing($this->clock('po'), (int) ($thresholds['lookback_days'] ?? 180));
        [$range, $bind] = $w->sql('order_date');

        $rows = DB::select(
            "SELECT supplier_id, MIN(supplier) AS supplier, LOWER(TRIM(supplier)) AS supplier_key, sku, MAX(product_id) AS product_id,
                    COUNT(*) AS pos, AVG(COALESCE(qty_received, 0) / qty_ordered) AS avg_fill,
                    SUM(qty_ordered - COALESCE(qty_received, 0)) AS short_units
             FROM purchase_orders
             WHERE tenant_id = ? AND qty_ordered > 0 AND supplier IS NOT NULL AND {$range}
               AND (received_date IS NOT NULL OR qty_received IS NOT NULL)
             GROUP BY supplier_id, LOWER(TRIM(supplier)), sku
             HAVING COUNT(*) >= ? AND AVG(COALESCE(qty_received, 0) / qty_ordered) <= ?",
            array_merge([$tenantId], $bind, [$minPos, $fillFrac])
        );

        foreach ($rows as $r) {
            $shortUnits = max(0.0, (float) $r->short_units);
            $cost  = $this->unitCost($r->sku);
            $value = $shortUnits * $cost;
            if ($cost > 0 && $value < $minValue) {
                continue;
            }
            $fill = round((float) $r->avg_fill * 100);
            $this->flag($tenantId, 'supplier_fill_rate', $cost > 0 ? $this->severityFromImpact($value) : Anomaly::SEVERITY_MEDIUM,
                $r->sku, null, $r->product_id,
                "Supplier '{$r->supplier}' filled SKU {$r->sku} at {$fill}% on average across " . (int) $r->pos
                . " POs ordered in the {$w->days()} days to {$w->lastDate()} (" . round($shortUnits) . ' units short, ' . $this->money($value) . ').',
                ['supplier' => $r->supplier, 'pos' => (int) $r->pos, 'avg_fill_pct' => $fill, 'short_units' => round($shortUnits, 1),
                 'lookback_days' => $w->days(), 'goods_value' => round($value, 2)],
                $this->supplierSubject($r->supplier_id !== null ? (int) $r->supplier_id : null, $r->supplier_key)
            );
        }
    }

    private function detectSupplierLeadTimeDriftV2(int $tenantId, array $thresholds): void
    {
        $pct    = (float) ($thresholds['pct'] ?? 30);
        $minPos = (int) ($thresholds['min_pos'] ?? 3);
        $recent = Window::trailing($this->clock('po'), 90);
        $prior  = $recent->before(90);

        $rows = DB::select(
            "SELECT supplier_id, MIN(supplier) AS supplier, LOWER(TRIM(supplier)) AS supplier_key,
                    AVG(received_date::date - order_date::date) FILTER (WHERE order_date >= ?) AS recent_avg,
                    COUNT(*) FILTER (WHERE order_date >= ?) AS recent_n,
                    AVG(received_date::date - order_date::date) FILTER (WHERE order_date < ?) AS prior_avg,
                    COUNT(*) FILTER (WHERE order_date < ?) AS prior_n
             FROM purchase_orders
             WHERE tenant_id = ? AND supplier IS NOT NULL AND received_date IS NOT NULL
               AND order_date >= ? AND order_date < ?
             GROUP BY supplier_id, LOWER(TRIM(supplier))",
            [$recent->fromDate(), $recent->fromDate(), $recent->fromDate(), $recent->fromDate(),
             $tenantId, $prior->fromDate(), $recent->untilDate()]
        );

        foreach ($rows as $r) {
            if ((int) $r->recent_n < $minPos || (int) $r->prior_n < $minPos || (float) $r->prior_avg <= 0) {
                continue;
            }
            $recentAvg = (float) $r->recent_avg;
            $priorAvg  = (float) $r->prior_avg;
            $drift     = ($recentAvg - $priorAvg) / $priorAvg * 100;
            if ($drift < $pct) {
                continue;
            }
            $this->flag($tenantId, 'supplier_lead_time_drift', 'medium', null, null, null,
                "Supplier '{$r->supplier}' lead time grew " . round($drift) . '% — ' . round($recentAvg, 1)
                . " days on POs ordered in the last 90 days vs " . round($priorAvg, 1) . ' days in the 90 before.',
                ['supplier' => $r->supplier, 'recent_avg_days' => round($recentAvg, 1), 'prior_avg_days' => round($priorAvg, 1),
                 'drift_pct' => round($drift, 1), 'recent_pos' => (int) $r->recent_n, 'prior_pos' => (int) $r->prior_n],
                $this->supplierSubject($r->supplier_id !== null ? (int) $r->supplier_id : null, $r->supplier_key)
            );
        }
    }

    /**
     * The newest priced PO of a SKU (ordered in the last `lookback_days`)
     * against the average of its POs in the year before it, or the product's
     * standard cost when it has none.
     */
    private function detectCostSpikeV2(int $tenantId, array $thresholds): void
    {
        $pct = (float) ($thresholds['pct'] ?? 25);
        $w   = Window::trailing($this->clock('po'), (int) ($thresholds['lookback_days'] ?? 90));

        $rows = DB::select(
            "WITH latest AS (
                SELECT DISTINCT ON (sku) id, sku, po_number, supplier, unit_cost, order_date, product_id
                FROM purchase_orders
                WHERE tenant_id = ? AND unit_cost > 0 AND order_date >= ? AND order_date < ?
                ORDER BY sku, order_date DESC, id DESC
             )
             SELECT l.*, (SELECT AVG(p.unit_cost) FROM purchase_orders p
                          WHERE p.tenant_id = ? AND p.sku = l.sku AND p.unit_cost > 0 AND p.id <> l.id
                            AND p.order_date <= l.order_date AND p.order_date > l.order_date - INTERVAL '365 days') AS hist_avg
             FROM latest l",
            [$tenantId, $w->fromDate(), $w->untilDate(), $tenantId]
        );

        foreach ($rows as $r) {
            $latestCost = (float) $r->unit_cost;
            [$baseline, $kind] = $r->hist_avg !== null
                ? [(float) $r->hist_avg, 'its average PO cost over the prior year']
                : [$this->unitCost($r->sku), 'its standard cost'];
            if ($baseline <= 0) {
                continue;
            }
            $spike = ($latestCost - $baseline) / $baseline * 100;
            if ($spike < $pct) {
                continue;
            }
            $this->flag($tenantId, 'cost_spike', 'high', $r->sku, null, $r->product_id,
                "PO #{$r->po_number} from {$r->supplier} prices SKU {$r->sku} at " . $this->money($latestCost) . ' — '
                . round($spike) . "% above {$kind} (" . $this->money($baseline) . ').',
                ['supplier' => $r->supplier, 'po_number' => $r->po_number, 'latest_cost' => $latestCost,
                 'baseline' => round($baseline, 4), 'baseline_kind' => $r->hist_avg !== null ? 'historical avg' : 'standard cost',
                 'spike_pct' => round($spike, 1)]
            );
        }
    }

    // ── Financial ────────────────────────────────────────────────────────────

    /**
     * The average selling price per (store, SKU, day) — promotion lines
     * excluded — against the SKU's median daily price in the 90 days before
     * the window. Flags a store that priced away from it on ≥ `min_days` days.
     */
    private function detectPriceAnomalyV2(int $tenantId, array $thresholds): void
    {
        $pct     = (float) ($thresholds['pct'] ?? 25);
        $minDays = (int) ($thresholds['min_days'] ?? 3);
        $w       = Window::trailing($this->clock('sales'), 30);
        $ref     = $w->before(90);
        $dev     = 'ABS(d.p - ref.r) / ref.r * 100 >= ?';

        $rows = DB::select(
            "WITH d AS (
                SELECT store_id, sku, date, SUM(unit_price * quantity) / SUM(quantity) AS p, SUM(quantity) AS q, MAX(product_id) AS product_id
                FROM sales_transactions
                WHERE tenant_id = ? AND date >= ? AND date < ? AND unit_price > 0 AND quantity > 0
                  AND promotion_ref IS NULL AND store_id IS NOT NULL
                GROUP BY store_id, sku, date
             ), ref AS (
                SELECT sku, percentile_cont(0.5) WITHIN GROUP (ORDER BY p) AS r
                FROM (SELECT sku, date, SUM(unit_price * quantity) / SUM(quantity) AS p
                      FROM sales_transactions
                      WHERE tenant_id = ? AND date >= ? AND date < ? AND unit_price > 0 AND quantity > 0 AND promotion_ref IS NULL
                      GROUP BY sku, date) x
                GROUP BY sku HAVING COUNT(*) >= 5
             )
             SELECT d.store_id, d.sku, MAX(d.product_id) AS product_id, ref.r,
                    COUNT(*) FILTER (WHERE {$dev}) AS dev_days, COUNT(*) AS days,
                    MIN(d.p) FILTER (WHERE {$dev}) AS min_p, MAX(d.p) FILTER (WHERE {$dev}) AS max_p,
                    COALESCE(SUM(GREATEST(0, ref.r - d.p) * d.q) FILTER (WHERE {$dev}), 0) AS leak
             FROM d JOIN ref ON ref.sku = d.sku AND ref.r > 0
             GROUP BY d.store_id, d.sku, ref.r
             HAVING COUNT(*) FILTER (WHERE {$dev}) >= ?",
            [$tenantId, $w->fromDate(), $w->untilDate(), $tenantId, $ref->fromDate(), $ref->untilDate(),
             $pct, $pct, $pct, $pct, $pct, $minDays]
        );

        foreach ($rows as $r) {
            $refPrice = (float) $r->r;
            $worst    = abs((float) $r->min_p - $refPrice) >= abs((float) $r->max_p - $refPrice) ? (float) $r->min_p : (float) $r->max_p;
            $direction = $worst > $refPrice ? 'above' : 'below';
            $this->flag($tenantId, 'price_anomaly', 'low', $r->sku, (int) $r->store_id, $r->product_id,
                "SKU {$r->sku} sold at store {$r->store_id} away from its usual price on " . (int) $r->dev_days . ' of ' . (int) $r->days
                . " days in the 30 days to {$w->lastDate()} — as far as " . $this->money($worst) . " ({$direction} the usual "
                . $this->money($refPrice) . ', promotions excluded).',
                ['reference_price' => round($refPrice, 4), 'worst_price' => round($worst, 4), 'deviating_days' => (int) $r->dev_days,
                 'days' => (int) $r->days, 'deviation_pct' => round(abs($worst - $refPrice) / $refPrice * 100, 1),
                 'revenue_impact' => round((float) $r->leak, 2)]
            );
        }
    }

    /** Days a store sold a SKU below its unit cost (promotions excluded), with the margin lost. */
    private function detectMarginErosionV2(int $tenantId, array $thresholds): void
    {
        $minDays = (int) ($thresholds['min_days'] ?? 2);
        $w = Window::trailing($this->clock('sales'), 30);

        $rows = DB::select(
            "WITH d AS (
                SELECT t.store_id, t.sku, t.date, SUM(t.unit_price * t.quantity) / SUM(t.quantity) AS p, SUM(t.quantity) AS q,
                       MAX(t.product_id) AS product_id
                FROM sales_transactions t
                WHERE t.tenant_id = ? AND t.date >= ? AND t.date < ? AND t.unit_price > 0 AND t.quantity > 0
                  AND t.promotion_ref IS NULL AND t.store_id IS NOT NULL
                GROUP BY t.store_id, t.sku, t.date
             )
             SELECT d.store_id, d.sku, MAX(d.product_id) AS product_id, pr.unit_cost AS cost,
                    COUNT(*) AS below_days, MIN(d.p) AS worst, SUM((pr.unit_cost - d.p) * d.q) AS lost
             FROM d JOIN products pr ON pr.tenant_id = ? AND pr.sku = d.sku AND pr.unit_cost > 0
             WHERE d.p < pr.unit_cost
             GROUP BY d.store_id, d.sku, pr.unit_cost
             HAVING COUNT(*) >= ?",
            [$tenantId, $w->fromDate(), $w->untilDate(), $tenantId, $minDays]
        );

        foreach ($rows as $r) {
            $this->flag($tenantId, 'margin_erosion', 'high', $r->sku, (int) $r->store_id, $r->product_id,
                "SKU {$r->sku} sold below its unit cost of " . $this->money((float) $r->cost) . " at store {$r->store_id} on "
                . (int) $r->below_days . " day(s) in the 30 days to {$w->lastDate()} — as low as " . $this->money((float) $r->worst)
                . ' (' . $this->money((float) $r->lost) . ' of margin lost).',
                ['unit_cost' => (float) $r->cost, 'worst_sale_price' => round((float) $r->worst, 4), 'below_cost_days' => (int) $r->below_days,
                 'margin_lost' => round((float) $r->lost, 2)]
            );
        }
    }

    /** Stock value on hand now (current positions) for SKUs with no sales in the window — WP6.3: in SQL. */
    private function detectSlowMovingCapitalV2(int $tenantId, array $thresholds): void
    {
        if (! $this->hasStoresWithSales($tenantId)) {
            return;
        }
        $minValue = (float) ($thresholds['min_value'] ?? 1000);
        $w = Window::trailing($this->clock('sales'), (int) ($thresholds['days'] ?? 60));

        foreach ($this->unsoldHoldings($tenantId, $w) as $h) {
            $sku  = (string) $h->sku;
            $qty  = (float) $h->qty;
            $cost = $this->unitCost($sku);
            if ($cost <= 0) {
                continue;
            }
            $value = $qty * $cost;
            if ($value < $minValue) {
                continue;
            }
            $this->flag($tenantId, 'slow_moving_capital', 'medium', $sku, null, $h->product_id,
                "SKU {$sku} has " . $this->money($value) . ' tied up in stock on hand (' . round($qty) . ' units × '
                . $this->money($cost) . ") with no sales in the {$w->days()} days to {$w->lastDate()}.",
                ['on_hand_qty' => $qty, 'unit_cost' => $cost, 'inventory_value' => round($value, 2), 'days_without_sales' => $w->days()]
            );
        }
    }

    // ── Store performance ────────────────────────────────────────────────────

    /**
     * store_outlier (audit C8). For each SKU sold in ≥ `min_stores` stores,
     * each store's rate this window is divided by its own rate in the 28 days
     * before (so big and small stores compare fairly). A store whose ratio sits
     * ≥ `z` robust deviations below its peers' median ratio — and ≥ `pct` below
     * what that median predicts for it — is flagged, on that store.
     */
    private function detectStoreOutlierV2(int $tenantId, array $thresholds): void
    {
        $pct       = (float) ($thresholds['pct'] ?? 50);
        $minValue  = (float) ($thresholds['min_value'] ?? self::DEFAULT_MIN_REVENUE);
        $minZ      = (float) ($thresholds['z'] ?? self::V2_MIN_Z);
        $minStores = (int) ($thresholds['min_stores'] ?? 4);
        $minUnits  = (float) ($thresholds['min_units'] ?? 10);
        $recentW   = Window::trailing($this->clock('sales'), (int) ($thresholds['days'] ?? 7));
        $histW     = $recentW->before(28);
        [$scopeSql, $scopeBind] = $this->scopeSql('sku');

        // WP6.3 (audit H31): peers, medians and robust z in SQL — only the
        // outlying (store, SKU) pairs come back, never every position's windows.
        // Same maths as before: ratio = this window's daily rate / the prior
        // 28 days' rate; median and MAD over the SKU's qualifying stores.
        $rows = DB::cursor(
            "WITH w AS (
                SELECT store_id, sku,
                       SUM(units_sold) FILTER (WHERE date >= ?) AS recent,
                       SUM(units_sold) FILTER (WHERE date <  ?) AS hist
                  FROM sales_daily
                 WHERE tenant_id = ? AND store_id IS NOT NULL AND date >= ? AND date < ?{$scopeSql}
                 GROUP BY store_id, sku
             ), r AS (
                SELECT store_id, sku, hist / ?::numeric AS prior, COALESCE(recent, 0) / ?::numeric AS rec
                  FROM w WHERE hist > 0
             ), q AS (
                SELECT r.*, rec / prior AS ratio FROM r WHERE prior * ? >= ?
             ), med AS (
                SELECT sku, COUNT(*) AS n, percentile_cont(0.5) WITHIN GROUP (ORDER BY ratio) AS median
                  FROM q GROUP BY sku HAVING COUNT(*) >= ?
             ), mad AS (
                SELECT q.sku, percentile_cont(0.5) WITHIN GROUP (ORDER BY abs(q.ratio - m.median)) AS mad
                  FROM q JOIN med m ON m.sku = q.sku GROUP BY q.sku
             )
             SELECT q.store_id, q.sku, q.prior, q.rec, q.ratio, m.median, m.n,
                    GREATEST(1.4826 * d.mad, 0.05 * m.median) AS spread
               FROM q JOIN med m ON m.sku = q.sku JOIN mad d ON d.sku = q.sku
              WHERE m.median > 0 AND (m.median - q.ratio) / GREATEST(1.4826 * d.mad, 0.05 * m.median) >= ?
              ORDER BY q.sku, q.store_id",
            array_merge(
                [$recentW->fromDate(), $recentW->fromDate(), $tenantId, $histW->fromDate(), $recentW->untilDate()], $scopeBind,
                [$histW->days(), $recentW->days(), $recentW->days(), $minUnits, $minStores, $minZ]
            )
        );

        foreach ($rows as $row) {
            $sku      = (string) $row->sku;
            $storeId  = (int) $row->store_id;
            $median   = (float) $row->median;
            $ratio    = (float) $row->ratio;
            $z        = ($median - $ratio) / (float) $row->spread;
            $peers    = (int) $row->n - 1;
            $expected = (float) $row->prior * $median * $recentW->days();
            $actual   = (float) $row->rec * $recentW->days();
            $shortPct = ($expected - $actual) / $expected * 100;
            if ($shortPct < $pct) {
                continue;
            }
            $price = $this->unitPrice($sku);
            $value = ($expected - $actual) * $price;
            if ($price > 0 && $value < $minValue) {
                continue;
            }
            // W10: this store's own promotion inflated its prior weeks (or it is in the post-promo dip).
            if ($this->promoExplains($tenantId, 'store_outlier', $sku, $storeId, $histW->fromDate(), $recentW->lastDate())) {
                continue;
            }
            $this->flag($tenantId, 'store_outlier', $price > 0 ? $this->severityFromImpact($value) : Anomaly::SEVERITY_MEDIUM,
                $sku, $storeId, null,
                "SKU {$sku} sold " . round($actual) . " units at store {$storeId} in the {$recentW->days()} days to {$recentW->lastDate()} — "
                . round($shortPct) . '% below the ' . round($expected) . " its own history and its {$peers} peer stores predict.",
                ['location_qty' => round($actual, 1), 'expected_qty' => round($expected, 1), 'shortfall_pct' => round($shortPct, 1),
                 'store_ratio' => round($ratio, 3), 'peer_median_ratio' => round($median, 3), 'z_score' => round($z, 2),
                 'peer_stores' => $peers, 'days' => $recentW->days(), 'revenue_impact' => round($value, 2)]
            );
        }
    }

    /** @param  float[]  $values */
    private static function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $n = count($values);
        $m = intdiv($n, 2);

        return $n % 2 ? (float) $values[$m] : ($values[$m - 1] + $values[$m]) / 2;
    }

    // ── Data quality ─────────────────────────────────────────────────────────

    /** A receipt loaded by more than one import — one anomaly per receipt. */
    private function detectDuplicateTransactionIdsV2(int $tenantId): void
    {
        DB::table('sales_transactions')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('transaction_id')
            ->whereNotNull('import_id')
            ->selectRaw('transaction_id, COUNT(*) AS cnt, COUNT(DISTINCT import_id) AS imports, MIN(store_id) AS store_id')
            ->groupBy('transaction_id')
            ->havingRaw('COUNT(DISTINCT import_id) > 1')
            ->orderByRaw('MAX(id) DESC')
            ->limit(50)
            ->get()
            ->each(fn ($dup) => $this->flag($tenantId, 'duplicate_transaction_ids', 'high', null, $dup->store_id !== null ? (int) $dup->store_id : null, null,
                "Receipt '{$dup->transaction_id}' was loaded by {$dup->imports} different imports ({$dup->cnt} lines) — possible duplicate import.",
                ['transaction_id' => $dup->transaction_id, 'count' => (int) $dup->cnt, 'imports' => (int) $dup->imports],
                'receipt:' . $dup->transaction_id
            ));
    }

    // =========================================================================
    // W10 (WP10.6) — TENANT-DEFINED RULES
    // =========================================================================

    /** @var array<string,string> custom rule key => why it could not run this time */
    private array $customFailed = [];

    /**
     * Every active rule the tenant wrote, on every store × SKU position. A hit
     * is an anomaly of type custom_rule whose subject is the rule's key, so two
     * rules on the same position are two anomalies, and each reconciles on its
     * own. A rule whose formula no longer compiles, or that throws, is skipped
     * and its open anomalies are left as they are — never cleared by a typo.
     */
    private function detectCustomRules(int $tenantId, array $thresholds): void
    {
        $this->customFailed = [];
        $rules = \App\Models\CustomRuleDefinition::where('tenant_id', $tenantId)->where('active', true)->orderBy('key')->get();
        if ($rules->isEmpty()) {
            return;
        }
        $cap = max(1, (int) ($thresholds['max_flags_per_rule'] ?? 5000));
        $stores = Store::where('tenant_id', $tenantId)->pluck('name', 'id')->all();
        $count = [];
        $failed = [];

        foreach (app(\App\Platform\Extensibility\CustomRuleEngine::class)->hits($tenantId, $rules, $failed) as $hit) {
            $rule = $hit['rule'];
            if (($count[$rule->key] = ($count[$rule->key] ?? 0) + 1) > $cap) {
                continue;
            }
            $pos = $hit['position'];
            $valueType = $rule->value_type ?: \App\Support\Detection\ValueModel::DATA_QUALITY;
            $where = $pos['store_id'] !== null ? ($stores[$pos['store_id']] ?? "store #{$pos['store_id']}") : 'all stores';
            $inputs = collect($hit['inputs'])->map(fn ($v, $k) => $k . ' ' . ($v === null ? '—' : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.')))->implode(', ');
            $money = $hit['impact'] !== null ? ' — ' . $this->currency . ' ' . number_format($hit['impact'], 0) . ' at stake' : '';

            $context = [
                'custom_key'   => $rule->key,
                'custom_label' => $rule->label,
                'formula'      => $rule->formula,
                'inputs'       => $hit['inputs'],
                'value_type'   => $valueType,
            ];
            if ($hit['impact'] !== null) {
                // Only lost revenue is "revenue at risk"; any other kind of money
                // is reported as a value, never added to it (WP4.4).
                $context[$valueType === \App\Support\Detection\ValueModel::LOST_REVENUE ? 'revenue_impact' : 'value_impact'] = $hit['impact'];
            }

            $this->flagV2(
                $tenantId, 'custom_rule',
                \App\Models\CustomRuleDefinition::ANOMALY_SEVERITY[$rule->severity] ?? Anomaly::SEVERITY_MEDIUM,
                $pos['sku'], $pos['store_id'], $pos['product_id'],
                "{$rule->label}: SKU {$pos['sku']} at {$where}" . ($inputs !== '' ? " ({$inputs})" : '') . $money . '.',
                $context,
                'custom:' . $rule->key,
            );
        }

        foreach ($count as $key => $n) {
            if ($n > $cap) {
                \Illuminate\Support\Facades\Log::warning("[detect] tenant {$tenantId}: custom rule {$key} matched {$n} positions; flagged the first {$cap}.");
            }
        }
        foreach ($failed as $key => $why) {
            \Illuminate\Support\Facades\Log::warning("[detect] tenant {$tenantId}: custom rule {$key} skipped — {$why}");
        }
        $this->customFailed = $failed;
    }

    /** A custom rule that did not run this time judges nothing: its anomalies stay as they are. */
    private function customEvaluability(): \Closure
    {
        $failed = $this->customFailed;

        return fn (Anomaly $a): bool => ! isset($failed[$a->context['custom_key'] ?? '']);
    }

    // =========================================================================
    // W11 — FRESH & EXPIRY
    // =========================================================================

    /**
     * Stock that will expire before it sells. Per position, the batches on
     * hand at its current snapshot are sold first-expiry-first-out at the
     * position's rate of sale over the last 28 days; whatever a batch cannot
     * sell by its expiry date is at risk. Only positions with a batch
     * expiring within the horizon are judged; stock already past its date is
     * all at risk.
     */
    private function detectExpiryRisk(int $tenantId, array $thresholds): void
    {
        $horizon  = max(1, (int) ($thresholds['horizon_days'] ?? 14));
        $minValue = (float) ($thresholds['min_value'] ?? 100);
        $asOf     = $this->clock('inventory');
        $sw       = Window::trailing($this->clock('sales'), 28);

        $rows = DB::cursor(
            "WITH pos AS (
                SELECT c.store_id, c.sku, c.as_of_date, c.product_id, c.unit_cost
                  FROM inventory_current c
                 WHERE c.tenant_id = ? AND c.as_of_date >= ? AND c.on_hand_qty > 0
                   AND c.earliest_expiry IS NOT NULL AND c.earliest_expiry <= ?::date
             )
             SELECT p.store_id, p.sku, p.product_id, p.unit_cost, p.as_of_date::text AS as_of,
                    l.expiry_date::text AS expiry, SUM(l.on_hand_qty) AS qty,
                    (SELECT COALESCE(SUM(s.units_sold), 0) FROM sales_daily s
                      WHERE s.tenant_id = ? AND s.sku = p.sku AND s.store_id IS NOT DISTINCT FROM p.store_id
                        AND s.date >= ? AND s.date < ?) AS sold28
               FROM pos p
               JOIN inventory_levels l ON l.tenant_id = ? AND l.sku = p.sku
                AND l.store_id IS NOT DISTINCT FROM p.store_id AND l.as_of_date = p.as_of_date
              WHERE l.expiry_date IS NOT NULL AND l.on_hand_qty > 0
              GROUP BY p.store_id, p.sku, p.product_id, p.unit_cost, p.as_of_date, l.expiry_date
              ORDER BY p.store_id, p.sku, l.expiry_date",
            [$tenantId, $this->inventoryFreshFrom(), $asOf->copy()->addDays($horizon)->toDateString(),
             $tenantId, $sw->fromDate(), $sw->untilDate(), $tenantId]
        );

        $stores = Store::where('tenant_id', $tenantId)->pluck('name', 'id');
        $flush = function (?array $p) use ($tenantId, $minValue, $stores) {
            if ($p === null) {
                return;
            }
            $rate = (float) $p['sold28'] / 28;
            $sold = 0.0;
            $atRisk = 0.0;
            $firstRisk = null;
            foreach ($p['batches'] as [$expiry, $qty, $days]) {
                $capacity = max(0.0, $rate * max(0, $days) - $sold);
                $sell = min($qty, $capacity);
                $sold += $sell;
                if ($qty - $sell > 0.0001) {
                    $atRisk += $qty - $sell;
                    $firstRisk ??= [$expiry, $days];
                }
            }
            if ($atRisk < 1 || $firstRisk === null) {
                return;
            }
            $cost = (float) ($p['unit_cost'] ?: $this->unitCost($p['sku']));
            $value = round($atRisk * $cost, 2);
            if ($cost > 0 && $value < $minValue) {
                return;
            }
            [$expiry, $days] = $firstRisk;
            $severity = $days <= 3 && $value >= 5 * $minValue ? 'high' : ($value >= 2.5 * $minValue || $cost <= 0 ? 'medium' : 'low');
            $where = $p['store_id'] ? ($stores[$p['store_id']] ?? "store #{$p['store_id']}") : 'all stores';
            $this->flag($tenantId, 'expiry_risk', $severity, $p['sku'], $p['store_id'], $p['product_id'],
                "SKU {$p['sku']} at {$where}: about " . round($atRisk) . ' unit(s) will not sell before they expire'
                . ($days < 0 ? ' (already past their date on ' . $expiry . ')' : " (first on {$expiry})")
                . ' at the current ' . round($rate, 1) . ' a day' . ($value > 0 ? ' — ' . $this->money($value) . ' at cost' : '')
                . '. Mark down, move to a faster store, or hold the next order.',
                ['units_at_risk' => round($atRisk, 2), 'first_expiry' => $expiry, 'days_to_expiry' => $days,
                 'daily_rate' => round($rate, 3), 'unit_cost' => $cost, 'inventory_value' => $value]
            );
        };

        $cur = null;
        foreach ($rows as $r) {
            $key = ($r->store_id ?? '') . '|' . $r->sku;
            if ($cur === null || $cur['key'] !== $key) {
                $flush($cur);
                $cur = ['key' => $key, 'store_id' => $r->store_id !== null ? (int) $r->store_id : null, 'sku' => (string) $r->sku,
                    'product_id' => $r->product_id, 'unit_cost' => $r->unit_cost, 'sold28' => (float) $r->sold28, 'batches' => []];
            }
            $days = (int) Carbon::parse($r->as_of)->diffInDays(Carbon::parse($r->expiry), false);
            $cur['batches'][] = [$r->expiry, (float) $r->qty, $days];
        }
        $flush($cur);
    }

    /**
     * A store wasting a large and rising share of an item: waste ÷ (sold +
     * waste) over the window, at least `pct`, worth at least `min_value` at
     * cost, and at least 1.5× its own rate over the eight weeks before (or
     * twice `pct` when there is no earlier waste to compare with).
     */
    private function detectWasteRate(int $tenantId, array $thresholds): void
    {
        $days     = max(7, (int) ($thresholds['days'] ?? 28));
        $pct      = (float) ($thresholds['pct'] ?? 10);
        $minValue = (float) ($thresholds['min_value'] ?? 200);
        $last = DB::table('waste_events')->where('tenant_id', $tenantId)->max('date');
        if (! $last) {
            return; // no waste feed
        }
        $clock = Carbon::parse($last)->startOfDay();
        $today = Carbon::today();
        $clock = $clock->gt($today) ? $today : $clock;
        $w     = Window::trailing($clock, $days);
        $prior = Window::trailing(Carbon::parse($w->fromDate())->subDay(), 56);

        $rows = DB::select(
            "WITH w AS (
                SELECT e.store_id, e.sku,
                       SUM(e.quantity) FILTER (WHERE e.date >= ? AND e.date < ?) AS wq,
                       SUM(COALESCE(e.value, e.quantity * COALESCE(p.unit_cost, 0))) FILTER (WHERE e.date >= ? AND e.date < ?) AS wv,
                       SUM(e.quantity) FILTER (WHERE e.date >= ? AND e.date < ?) AS pq,
                       MAX(p.id) AS product_id
                  FROM waste_events e LEFT JOIN products p ON p.tenant_id = e.tenant_id AND p.sku = e.sku
                 WHERE e.tenant_id = ? AND e.date >= ? AND e.date < ?
                 GROUP BY e.store_id, e.sku
             )
             SELECT w.*,
                    (SELECT COALESCE(SUM(s.units_sold), 0) FROM sales_daily s WHERE s.tenant_id = ? AND s.sku = w.sku
                        AND s.store_id IS NOT DISTINCT FROM w.store_id AND s.date >= ? AND s.date < ?) AS sold,
                    (SELECT COALESCE(SUM(s.units_sold), 0) FROM sales_daily s WHERE s.tenant_id = ? AND s.sku = w.sku
                        AND s.store_id IS NOT DISTINCT FROM w.store_id AND s.date >= ? AND s.date < ?) AS psold
               FROM w WHERE w.wv >= ?",
            [$w->fromDate(), $w->untilDate(), $w->fromDate(), $w->untilDate(), $prior->fromDate(), $prior->untilDate(),
             $tenantId, $prior->fromDate(), $w->untilDate(),
             $tenantId, $w->fromDate(), $w->untilDate(), $tenantId, $prior->fromDate(), $prior->untilDate(), $minValue]
        );

        $stores = Store::where('tenant_id', $tenantId)->pluck('name', 'id');
        foreach ($rows as $r) {
            $wq = (float) $r->wq;
            $rate = $wq + (float) $r->sold > 0 ? 100 * $wq / ($wq + (float) $r->sold) : 0.0;
            if ($rate < $pct) {
                continue;
            }
            $pq = (float) ($r->pq ?? 0);
            $priorRate = $pq + (float) $r->psold > 0 ? 100 * $pq / ($pq + (float) $r->psold) : null;
            if ($priorRate !== null && $priorRate > 0 ? $rate < 1.5 * $priorRate : $rate < 2 * $pct) {
                continue; // wasting a lot, but no more than it always has — not new
            }
            $value = round((float) $r->wv, 2);
            $where = $r->store_id ? ($stores[$r->store_id] ?? "store #{$r->store_id}") : 'all stores';
            $severity = $value >= 5 * $minValue || $rate >= 3 * $pct ? 'high' : 'medium';
            $this->flag($tenantId, 'waste_rate', $severity, (string) $r->sku, $r->store_id !== null ? (int) $r->store_id : null, $r->product_id,
                "SKU {$r->sku} at {$where}: " . round($wq) . ' unit(s) wasted in the ' . $w->days() . ' days to ' . $w->lastDate()
                . ' — ' . round($rate, 1) . '% of what went out' . ($priorRate !== null ? ' (was ' . round($priorRate, 1) . '%)' : '')
                . ', ' . $this->money($value) . ' at cost. Check ordering, rotation and shelf life.',
                ['waste_units' => $wq, 'waste_pct' => round($rate, 1), 'prior_waste_pct' => $priorRate !== null ? round($priorRate, 1) : null,
                 'sold_units' => (float) $r->sold, 'value_impact' => $value, 'window_days' => $w->days()]
            );
        }
    }
}
