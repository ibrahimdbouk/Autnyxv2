<?php

namespace App\Services\Anomaly;

use App\Models\Anomaly;
use App\Models\AnomalySetting;
use App\Models\Import;
use App\Models\InventoryLevel;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesTransaction;
use App\Models\SkuProfile;
use App\Models\Store;
use App\Services\Recovery\LifecycleReconciler;
use App\Support\Detection\ValueModel;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AnomalyDetectionService
{
    use Concerns\DetectsV2;

    public function __construct(
        private readonly BaselineCalculatorService $baselines
    ) {}

    /**
     * The analysis "now" — a fresh Carbon copy of the per-run analysis clock
     * ({@see $asOf}). Every data-analysis window anchors here instead of
     * $this->analysisDate(), so detection is measured relative to the latest data we
     * have, not the wall clock. Falls back to today when no clock was set.
     */
    private function analysisDate(): Carbon
    {
        return ($this->asOf ?? Carbon::now()->startOfDay())->copy();
    }

    /**
     * Resolve the analysis clock for a tenant: the latest date we have data for
     * (max of sales_daily.date and inventory as_of_date), capped at real today so
     * we never analyse into the future. No data → today.
     */
    private function resolveAsOf(int $tenantId): void
    {
        $today    = Carbon::now()->startOfDay();
        $maxSales = DB::table('sales_daily')->where('tenant_id', $tenantId)->max('date');
        $maxInv   = DB::table('inventory_levels')->where('tenant_id', $tenantId)->max('as_of_date');

        $latest = collect([$maxSales, $maxInv])
            ->filter()
            ->map(fn ($d) => Carbon::parse($d)->startOfDay())
            ->max();

        $this->asOf = ($latest && $latest->lt($today)) ? $latest : $today;
    }

    /**
     * Recovery lifecycle (R2/R2c) — rule families whose per-subject evaluability
     * the reconciler can confirm from a primed input set, so a cleared subject can
     * be advanced toward recovery only where the rule's input was actually present
     * this run. A subject whose input vanished stays dormant — a data gap is never
     * read as recovery.
     *
     * Each family is gated on the input that DETERMINES whether the rule could
     * evaluate the subject:
     *   • INVENTORY   → the primed on-hand snapshot (a subject with no inventory
     *                   row this run wasn't evaluated).
     *   • DEMAND      → the primed recent-demand set (no recent sales = not
     *                   evaluated; can't tell recovery from a quiet gap).
     *   • FIN_SALES   → recent priced sales (price_anomaly / margin_erosion read
     *                   the last-30d transaction stream; no recent sales = the
     *                   SKU couldn't be priced this run). Uses the demand set.
     *   • COST        → PurchaseOrder unit-cost rows (cost_spike can only flag a
     *                   SKU that HAS PO cost data; if that data vanished, dormant).
     *   • CAPITAL     → the on-hand snapshot (slow_moving_capital values on-hand
     *                   inventory; no inventory row = not evaluated).
     *
     * Rules NOT listed here keep the prior "rule ran and didn't flag = cleared"
     * fallback ON PURPOSE, because there a gap can't masquerade as recovery:
     *   • discount_signal — a pure product-master check (list price vs. cost); the
     *     master is present whenever detection runs, so a fixed price is real
     *     recovery, not a data gap.
     *   • revenue_concentration_risk / store_outlier — tenant/store-level (sku is
     *     null → already evaluable-by-default).
     *   • PO / supplier rules (po_overdue, receiving_discrepancy, po_late_receipt,
     *     supplier_fill_rate, supplier_lead_time_drift) — subjects keyed by PO /
     *     supplier, not sku/store; "PO no longer overdue = received" is genuine
     *     recovery, and strict gating would need PO/supplier priming that would
     *     freeze legitimate recoveries.
     *   • data-quality rules (import_frequency_gap, duplicate_transaction_ids,
     *     sku_master_drift, location_proliferation) — tenant/source-wide scans;
     *     "no longer firing = fixed" is exactly correct.
     */
    private const INVENTORY_COVERAGE = [
        'stockout_risk', 'safety_stock_breach', 'negative_inventory', 'overstock',
        'phantom_inventory', 'dead_stock', 'multi_location_imbalance', 'reorder_point_staleness',
    ];

    private const DEMAND_COVERAGE = [
        'sales_spike', 'sales_drop', 'demand_seasonality_breach', 'demand_erosion',
        'demand_forecast_break', 'return_rate_spike', 'cannibalization_signal', 'channel_mix_shift',
        'plan_variance',
    ];

    /** R2c — financial per-SKU rules that read the recent priced-sales stream. */
    private const FIN_SALES_COVERAGE = ['price_anomaly', 'margin_erosion'];

    /** R2c — cost_spike: only ever flags a SKU that has PurchaseOrder cost data. */
    private const COST_COVERAGE = ['cost_spike'];

    /** R2c — slow_moving_capital: values on-hand inventory per SKU. */
    private const CAPITAL_COVERAGE = ['slow_moving_capital'];

    /**
     * Tracks anomaly IDs touched by the current rule run.
     * Used to delete stale anomalies (condition resolved) while preserving investigation work.
     */
    private array $touchedAnomalyIds = [];

    /** Tenant currency (ISO code) for the current run — money in descriptions is labelled with it. */
    private string $currency = Money::DEFAULT;

    /** @var array<string,float>|null  sku => unit price (selling_price ?? unit_cost), primed per run */
    private ?array $priceMap = null;

    /** @var array<string,float>|null  sku => unit cost (unit_cost ?? selling_price), primed per run */
    private ?array $costMap = null;

    /**
     * R2c — SKUs that have PurchaseOrder unit-cost data this run, primed once.
     * cost_spike can only flag a SKU present here, so it is the exact evaluability
     * signal for the cost family: a SKU whose PO cost data vanished is a data gap,
     * not a recovery. @var array<string,true>|null
     */
    private ?array $poCostSkus = null;

    /**
     * Latest on-hand snapshot per "store_id|sku", primed ONCE per run and shared
     * by every inventory rule (stockout, phantom, negative, overstock, safety
     * stock). Each entry: ['qty','reorder','product_id','location','d'].
     * @var array<string,array>|null
     */
    private ?array $latestOnHand = null;

    /**
     * Recent units-sold per "store_id|sku" over $demandWindowDays, primed once
     * from the sales_daily aggregate and shared by the demand-aware inventory
     * rules. @var array<string,float>|null
     */
    private ?array $recentDemand = null;

    /**
     * B4: derived replenishment params per "store_id|sku" from sku_replenishment
     * (reorder_point, suggested_order_qty, order_up_to, supplier). Used to (a)
     * fall back a reorder point where the tenant supplied none — reviving
     * safety-stock and imbalance rules — and (b) attach a suggested order qty to
     * stockout alerts. Empty if the replenishment pass hasn't run → no change.
     * @var array<string,array>
     */
    private array $replenishment = [];

    /** Window (days) the shared recentDemand map was aggregated over. */
    private int $demandWindowDays = 30;

    /**
     * Analysis clock — the date every data-analysis window is measured relative to.
     * Set per run to the latest date we actually have data for (max of sales_daily
     * and inventory snapshots), capped at real today so it never runs into the
     * future. This decouples detection from wall-clock `today()`: a customer whose
     * feed is a few days behind should not have every SKU read as "no recent sales"
     * / "stale snapshot" simply because the calendar moved past their last import.
     * When data IS current, this equals today, so behaviour is unchanged.
     * Genuine real-world checks (import-feed freshness) keep using now().
     */
    private ?Carbon $asOf = null;

    /**
     * Best-fit rule gating (Phase 3). Segment per "store_id|sku" ("0|sku" =
     * chain-level), primed once per run from sku_profiles. A rule is skipped for
     * a (sku, store/chain) whose segment doesn't list it. Empty map (profiler
     * never ran) → no gating, so this is purely additive and safe.
     * @var array<string,string>
     */
    private array $skuSegments = [];
    private bool $gatingActive = false;
    private int $gatedFlags = 0;

    /**
     * Incremental detection (Slice 2): when set, the primed maps and the scoped
     * rule families below are restricted to this SKU set; null = full scan
     * (unchanged behaviour). Every scoping site is guarded by `->when($this->scope …)`
     * so a full run is byte-identical to before. See claude/incremental-detection-design.md.
     */
    private ?\App\Services\Detection\RunScope $scope = null;

    /**
     * Aggregate mode (Slice 4): run ONLY the rules the incremental per-key run
     * skips — the cross-SKU / absence / supplier-&-PO families — on a full scan.
     * The nightly cadence pairs a scoped incremental run (per-key) with an
     * aggregate run (the rest); the weekly full sweep is the catch-all backstop.
     */
    private bool $aggregateOnly = false;

    /** True when the most recent runForTenant() call deferred instead of scanning (WP1.2). */
    public bool $lastRunDeferred = false;

    /** WP4.1: the corrected rule set is active for the current run. */
    private bool $v2 = false;

    /** Forces v1/v2 for the next run (detection:diff); null = the tenant's setting. */
    private ?bool $forcedV2 = null;

    /**
     * Dry-run capture (detection:diff): when an array, flag() records what it
     * would write here and writes nothing, and nothing is reconciled.
     * @var array<int,array<string,mixed>>|null
     */
    private ?array $capture = null;

    /** v2: flags held back because the subject's last episode was dismissed and hasn't worsened (WP4.3). */
    private array $suppressedByRule = [];

    /** v2: rules that run whatever the item's demand segment (in addition to SkuProfile::ALWAYS_ON). */
    private const V2_ALWAYS_ON = ['reorder_point_staleness'];

    /** Is the corrected rule set on for this tenant? Its own setting wins over the platform default. */
    public static function rulesV2For(int $tenantId): bool
    {
        $settings = DB::table('tenants')->where('id', $tenantId)->value('settings');
        $settings = is_string($settings) ? (json_decode($settings, true) ?: []) : (array) $settings;

        return array_key_exists('detection_rules_v2', $settings)
            ? (bool) $settings['detection_rules_v2']
            : (bool) config('detection.rules_v2', false);
    }

    /**
     * Run detection without writing anything and return what it would flag:
     * one entry per anomaly (rule, identity, sku, store, subject, severity,
     * value). Used by detection:diff to compare v1 and v2 before a switch.
     *
     * @return array<int,array<string,mixed>>
     */
    public function dryRun(int $tenantId, bool $v2): array
    {
        $this->forcedV2 = $v2;
        $this->capture  = [];
        try {
            $this->runForTenant($tenantId);

            return $this->capture;
        } finally {
            $this->forcedV2 = null;
            $this->capture  = null;
        }
    }

    /** @return array<string,int> */
    public function suppressedByRule(): array { return $this->suppressedByRule; }

    public function rulesV2Active(): bool { return $this->v2; }

    /**
     * Why detection must wait for this tenant right now, or null if it may run.
     *
     * Bounded (WP1.2 / audit C3): only a *live* import blocks —
     *   • importing, touched within detection.import_block_minutes;
     *   • uploaded / mapping_review, created within detection.pending_import_block_hours
     *     (a multi-file load whose later parts are still being mapped).
     * Anything older is stale; imports:expire-abandoned retires it.
     */
    public function pendingImportBlock(int $tenantId): ?string
    {
        $importing = Import::where('tenant_id', $tenantId)
            ->where('status', Import::STATUS_IMPORTING)
            ->where('updated_at', '>=', now()->subMinutes((int) config('detection.import_block_minutes', 30)))
            ->count();
        if ($importing > 0) {
            return "{$importing} import(s) still writing rows";
        }

        $pending = Import::where('tenant_id', $tenantId)
            ->whereIn('status', [Import::STATUS_UPLOADED, Import::STATUS_MAPPING_REVIEW])
            ->where('created_at', '>=', now()->subHours((int) config('detection.pending_import_block_hours', 24)))
            ->count();
        if ($pending > 0) {
            return "{$pending} recent upload(s) awaiting mapping review";
        }

        return null;
    }

    /**
     * Rules that incremental mode runs. These read ONLY the SKU-scoped primed
     * maps or salesComparison(), so scoping those sources makes them incremental
     * with correct reconciler evaluability. Every other rule (raw-SQL per-key
     * rules and aggregate/absence rules) is skipped in incremental mode for now
     * and covered by the full run; Slice 2b/Slice 4 extend this set.
     */
    public const INCREMENTAL_RULES = [
        // Slice 2 — map-read + salesComparison families
        'stockout_risk',
        'negative_inventory',
        'overstock',
        'phantom_inventory',
        'safety_stock_breach',
        'multi_location_imbalance',
        'sales_spike',
        'sales_drop',
        // Slice 2b — raw-SQL per-key rules (scoped in their own queries)
        'inventory_shrinkage',
        'cumulative_shrink',
        'demand_erosion',
        'demand_forecast_break',
        // Actual-vs-ingested-plan: raw-SQL, scoped by sku via scopeSql('pf.sku').
        'plan_variance',
        // Planned-order vs receipts: raw-SQL, scoped by sku on both sources.
        'order_plan_variance',
    ];

    /** Rules that fire when data STOPS arriving — never reachable from dirty keys alone. */
    public const ABSENCE_RULES = ['sales_drop', 'demand_forecast_break', 'plan_variance'];

    /**
     * A guarded SQL fragment + bindings for raw DB::select() rules. Returns
     * ['', []] in full mode, so raw queries are byte-identical when unscoped.
     *
     * @return array{0:string,1:array<int,string>}
     */
    private function scopeSql(string $skuColumn = 'sku'): array
    {
        return $this->scope ? $this->scope->sqlClause($skuColumn) : ['', []];
    }

    /**
     * B2 validation telemetry: per-rule counts of flags actually emitted and
     * flags suppressed by best-fit gating in the current run. "Would-be flags"
     * for a rule = emitted + gated; gated / would-be is the noise the gate cut.
     * @var array<string,int>
     */
    private array $emittedByRule = [];
    private array $gatedByRule = [];

    /** Master switch for segment-based rule gating. */
    private const RULE_GATING_ENABLED = true;

    /**
     * Estimated revenue impact (in the tenant's currency) below which a
     * sales spike/drop is treated as noise and not flagged. Overridable per
     * rule via the 'min_revenue' threshold.
     */
    private const DEFAULT_MIN_REVENUE = 500.0;

    /**
     * Materiality floor for portfolio-TREND rules (demand_erosion,
     * demand_seasonality_breach). Trend signals are bulk by nature — a whole
     * category can drift at once — so a SKU whose trend is worth less than this
     * belongs in the campaign tail (reviewed in aggregate), not as its own
     * investigation. Higher than DEFAULT_MIN_REVENUE on purpose: it's the lever
     * that keeps the actionable list short. Tenant-overridable via min_revenue.
     */
    private const TREND_MIN_REVENUE = 2000.0;

    /** Forward decision horizon (days) for valuing a continuing demand trend. */
    private const TREND_HORIZON_DAYS = 30;

    /** Prime a sku => unit-price map once so impact estimates don't hit the DB per SKU. */
    private function primePriceMap(int $tenantId): void
    {
        $this->priceMap = [];
        $this->costMap  = [];

        Product::where('tenant_id', $tenantId)
            ->select(['sku', 'selling_price', 'unit_cost'])
            ->cursor()
            ->each(function ($p) {
                $sku = trim((string) $p->sku);
                $this->priceMap[$sku] = (float) ($p->selling_price ?: $p->unit_cost ?: 0);
                $this->costMap[$sku]  = (float) ($p->unit_cost ?: $p->selling_price ?: 0);
            });
    }

    /**
     * R2c — prime the set of SKUs that have PurchaseOrder unit-cost data. This is
     * the exact input cost_spike keys off (it groups PO rows by SKU), so a cleared
     * cost_spike anomaly may only be advanced toward recovery when its SKU still
     * appears here; if the PO cost data vanished the anomaly stays dormant. One
     * light DISTINCT query.
     */
    private function primeCostCoverage(int $tenantId): void
    {
        $this->poCostSkus = [];

        PurchaseOrder::where('tenant_id', $tenantId)
            ->where('unit_cost', '>', 0)
            ->distinct()
            ->pluck('sku')
            ->each(function ($sku): void {
                $this->poCostSkus[trim((string) $sku)] = true;
            });
    }

    /**
     * Prime the latest on-hand snapshot per (store, sku) with ONE indexed query.
     * Postgres DISTINCT ON returns a single row per combo — the most recent by
     * as_of_date — so we read ~one row per (store, sku) instead of streaming the
     * full inventory history into PHP once per rule. Backed by
     * idx_inv_latest_snapshot.
     */
    private function primeInventorySnapshot(int $tenantId): void
    {
        $this->latestOnHand = [];

        DB::table('inventory_levels')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('store_id')
            ->when($this->scope, fn ($q) => $this->scope->constrain($q))
            ->select(['store_id', 'sku', 'on_hand_qty', 'reorder_point', 'product_id', 'location', 'as_of_date'])
            ->orderByRaw('store_id, sku, as_of_date DESC NULLS LAST')
            ->distinct(['store_id', 'sku']) // DISTINCT ON (store_id, sku) via the Postgres driver
            ->cursor()
            ->each(function ($l) {
                $d = $l->as_of_date ? substr((string) $l->as_of_date, 0, 10) : '0000-00-00';
                $this->latestOnHand[$l->store_id . '|' . $l->sku] = [
                    'qty'        => (float) $l->on_hand_qty,
                    'reorder'    => $l->reorder_point !== null ? (float) $l->reorder_point : null,
                    'product_id' => $l->product_id,
                    'location'   => $l->location,
                    'd'          => $d,
                ];
            });
    }

    /**
     * Prime recent units-sold per (store, sku) over $windowDays with ONE grouped
     * aggregate (backed by idx_sales_daily_demand), shared by all demand-aware
     * inventory rules instead of each re-running the same GROUP BY.
     */
    private function primeRecentDemand(int $tenantId, int $windowDays): void
    {
        $this->recentDemand     = [];
        $this->demandWindowDays = max(1, $windowDays);

        $from = $this->analysisDate()->subDays($this->demandWindowDays)->format('Y-m-d');

        DB::table('sales_daily')
            ->where('tenant_id', $tenantId)
            ->where('date', '>=', $from)
            ->when($this->scope, fn ($q) => $this->scope->constrain($q))
            ->selectRaw('store_id, sku, SUM(units_sold) as u')
            ->groupBy('store_id', 'sku')
            ->cursor()
            ->each(function ($r) {
                $this->recentDemand[$r->store_id . '|' . $r->sku] = (float) $r->u;
            });
    }

    private function unitPrice(?string $sku): float
    {
        if ($sku === null || $this->priceMap === null) {
            return 0.0;
        }

        return $this->priceMap[trim($sku)] ?? 0.0;
    }

    /**
     * B4: load derived replenishment params, then fall back the reorder point in
     * the shared snapshot wherever the tenant supplied none. This revives the
     * reorder-point-dependent rules (safety stock, imbalance) and sharpens
     * stockout — without ever overwriting a reorder point the tenant did supply.
     */
    private function primeReplenishment(int $tenantId): void
    {
        $this->replenishment = [];

        if (! DB::getSchemaBuilder()->hasTable('sku_replenishment')) {
            return;
        }

        DB::table('sku_replenishment')
            ->where('tenant_id', $tenantId)
            ->when($this->scope, fn ($q) => $this->scope->constrain($q))
            ->select(['store_id', 'sku', 'reorder_point', 'suggested_order_qty', 'order_up_to', 'supplier'])
            ->cursor()
            ->each(function ($r) {
                $this->replenishment[$r->store_id . '|' . trim((string) $r->sku)] = [
                    'reorder_point'       => (float) $r->reorder_point,
                    'suggested_order_qty' => (float) $r->suggested_order_qty,
                    'order_up_to'         => (float) $r->order_up_to,
                    'supplier'            => $r->supplier,
                ];
            });

        // Backfill null reorder points in the shared snapshot from the derived
        // value. Iterate the property DIRECTLY — `$this->latestOnHand ?? []` would
        // make foreach reference a temporary copy, so the writes would be lost.
        if ($this->latestOnHand !== null) {
            foreach ($this->latestOnHand as $k => &$oh) {
                if (($oh['reorder'] ?? null) === null) {
                    $rp = $this->replenishment[$k]['reorder_point'] ?? null;
                    if ($rp !== null && $rp > 0) {
                        $oh['reorder']        = $rp;
                        $oh['reorder_source'] = 'derived';
                    }
                }
            }
            unset($oh);
        }
    }

    /**
     * Load the per (store, sku) and chain-level segments so flag() can gate rules
     * that don't fit an item's demand shape. If sku_profiles is empty for the
     * tenant, gating stays off (no behavior change).
     */
    private function primeSkuSegments(int $tenantId): void
    {
        $this->skuSegments = [];
        $this->gatedFlags  = 0;
        $this->gatingActive = false;
        $this->emittedByRule = [];
        $this->gatedByRule   = [];

        if (! self::RULE_GATING_ENABLED) return;

        DB::table('sku_profiles')
            ->where('tenant_id', $tenantId)
            ->when($this->scope, fn ($q) => $this->scope->constrain($q))
            ->select(['store_id', 'sku', 'segment'])
            ->cursor()
            ->each(function ($p) {
                $this->skuSegments[$p->store_id . '|' . trim((string) $p->sku)] = $p->segment;
            });

        $this->gatingActive = ! empty($this->skuSegments);
    }

    /**
     * Best-fit gate: should this rule fire for this (sku, store)? Store-level
     * flags check the store profile (falling back to chain); tenant-wide flags
     * (null store) check the chain profile. Unknown/absent profile → allowed.
     */
    private function ruleAppliesTo(string $ruleType, ?string $sku, ?int $storeId): bool
    {
        if (! $this->gatingActive || $sku === null) return true;
        if ($this->v2 && in_array($ruleType, self::V2_ALWAYS_ON, true)) return true;

        $sku = trim($sku);
        $segment = $storeId !== null
            ? ($this->skuSegments[$storeId . '|' . $sku] ?? $this->skuSegments['0|' . $sku] ?? null)
            : ($this->skuSegments['0|' . $sku] ?? null);

        if ($segment === null) return true; // no profile for this item → don't gate

        return SkuProfile::segmentAllowsRule($segment, $ruleType);
    }

    private function unitCost(?string $sku): float
    {
        if ($sku === null || $this->costMap === null) {
            return 0.0;
        }

        return $this->costMap[trim($sku)] ?? 0.0;
    }

    /** Estimated revenue impact = |units affected| × unit price. */
    private function estimateImpact(?string $sku, float $unitsDelta): float
    {
        return abs($unitsDelta) * $this->unitPrice($sku);
    }

    /** Format a money amount in the tenant's currency for an anomaly description. */
    private function money(float $amount): string
    {
        return Money::format($amount, $this->currency);
    }

    /** B2: per-rule flags emitted in the last run. @return array<string,int> */
    public function emittedByRule(): array { return $this->emittedByRule; }

    /** B2: per-rule flags suppressed by best-fit gating in the last run. @return array<string,int> */
    public function gatedByRule(): array { return $this->gatedByRule; }

    /** B2: total flags suppressed by best-fit gating in the last run. */
    public function gatedFlags(): int { return $this->gatedFlags; }

    /** B2: whether best-fit gating was active (profiles present) in the last run. */
    public function gatingWasActive(): bool { return $this->gatingActive; }

    /** Map a revenue-impact figure to a severity tier so money drives priority. */
    private function severityFromImpact(float $impact): string
    {
        if ($impact >= 10000) return Anomaly::SEVERITY_HIGH;
        if ($impact >= 2000)  return Anomaly::SEVERITY_MEDIUM;

        return Anomaly::SEVERITY_LOW;
    }

    /**
     * Recovery lifecycle (R2): per-subject evaluability sets, built once from the
     * primed input maps — no per-rule instrumentation. A subject counts as
     * "evaluated" this run if the rule's input data was present for it, so a
     * cleared subject can be advanced toward recovery; a subject whose input
     * vanished stays dormant (never read as recovered).
     *
     * @return array{0:array<string,true>,1:array<string,true>,2:array<string,true>,3:array<string,true>,4:array<string,true>}
     *         [invPairs("store|sku"), invSkus(sku), demPairs("store|sku"), demSkus(sku), costSkus(sku)]
     */
    private function buildCoverageSets(): array
    {
        $invPairs = $invSkus = $demPairs = $demSkus = [];

        foreach (array_keys($this->latestOnHand ?? []) as $key) {
            $invPairs[$key] = true;
            $sku = strstr((string) $key, '|') !== false ? substr((string) $key, strpos((string) $key, '|') + 1) : (string) $key;
            $invSkus[$sku] = true;
        }
        foreach (array_keys($this->recentDemand ?? []) as $key) {
            $demPairs[$key] = true;
            $sku = strstr((string) $key, '|') !== false ? substr((string) $key, strpos((string) $key, '|') + 1) : (string) $key;
            $demSkus[$sku] = true;
        }

        // R2c — cost coverage is a pure SKU set primed from PurchaseOrder rows.
        $costSkus = $this->poCostSkus ?? [];

        return [$invPairs, $invSkus, $demPairs, $demSkus, $costSkus];
    }

    /**
     * Build the evaluability closure the reconciler uses for one rule. Strict
     * coverage gating (dormant on a data gap) applies to the per-SKU families
     * whose input we can confirm from a primed set — inventory, demand, and
     * (R2c) the financial-sales, cost, and capital families. Store/tenant-level
     * rules and the families where a gap cannot masquerade as recovery
     * (discount_signal, PO/supplier, data-quality) fall back to the prior "rule
     * ran and didn't flag = cleared" behaviour, on purpose (see the coverage
     * constants' docblock).
     *
     * @param  array<string,true>  $invPairs
     * @param  array<string,true>  $invSkus
     * @param  array<string,true>  $demPairs
     * @param  array<string,true>  $demSkus
     * @param  array<string,true>  $costSkus
     */
    private function evaluabilityFor(string $ruleType, array $invPairs, array $invSkus, array $demPairs, array $demSkus, array $costSkus): \Closure
    {
        $isInventory = in_array($ruleType, self::INVENTORY_COVERAGE, true);
        $isDemand    = in_array($ruleType, self::DEMAND_COVERAGE, true);
        // R2c families. Financial-sales and capital reuse the demand / inventory
        // primed sets (their input IS recent sales / on-hand inventory); cost has
        // its own PurchaseOrder-derived set.
        $isFinSales  = in_array($ruleType, self::FIN_SALES_COVERAGE, true);
        $isCost      = in_array($ruleType, self::COST_COVERAGE, true);
        $isCapital   = in_array($ruleType, self::CAPITAL_COVERAGE, true);

        return function (Anomaly $anomaly) use (
            $isInventory, $isDemand, $isFinSales, $isCost, $isCapital,
            $invPairs, $invSkus, $demPairs, $demSkus, $costSkus
        ): bool {
            $sku = $anomaly->sku;

            // No SKU, or a family we deliberately don't gate → prior behaviour
            // (a rule that ran and didn't flag = cleared), so those still resolve
            // instead of piling up.
            if ($sku === null || (! $isInventory && ! $isDemand && ! $isFinSales && ! $isCost && ! $isCapital)) {
                return true;
            }

            $pair = ($anomaly->store_id ?? '') . '|' . $sku;

            // On-hand inventory presence (inventory rules + capital rule).
            if ($isInventory) {
                return $anomaly->store_id !== null ? isset($invPairs[$pair]) : isset($invSkus[$sku]);
            }
            if ($isCapital) {
                // slow_moving_capital is sku-level (store null on the anomaly).
                return isset($invSkus[$sku]);
            }

            // PurchaseOrder cost presence (cost_spike, sku-level).
            if ($isCost) {
                return isset($costSkus[$sku]);
            }

            // Recent (priced) sales presence — demand family + financial-sales.
            return $anomaly->store_id !== null
                ? (isset($demPairs[$pair]) || isset($demSkus[$sku]))
                : isset($demSkus[$sku]);
        };
    }

    /**
     * Recovery lifecycle (R2b): a per-subject confirmation resolver the reconciler
     * uses to decide how many consecutive healthy runs confirm recovery. Keys off
     * the SKU's demand segment (from the primed profiles) — intermittent/lumpy
     * items need more confirmation than smooth series, so a quiet gap is not
     * mistaken for recovery. Falls back to the default when no profile exists.
     */
    private function confirmRunsResolver(): \Closure
    {
        $segments = $this->skuSegments;

        return function (Anomaly $anomaly) use ($segments): int {
            $sku = $anomaly->sku !== null ? trim($anomaly->sku) : null;
            if ($sku === null) {
                return LifecycleReconciler::DEFAULT_CONFIRM_RUNS;
            }
            $segment = $anomaly->store_id !== null
                ? ($segments[$anomaly->store_id . '|' . $sku] ?? $segments['0|' . $sku] ?? null)
                : ($segments['0|' . $sku] ?? null);

            return LifecycleReconciler::confirmRunsForSegment($segment);
        };
    }

    /**
     * Run all enabled rules for a tenant and store results in the anomalies table.
     * Existing open anomalies are upserted (investigation fields preserved); a
     * subject that stops failing is advanced through the recovery lifecycle (R2).
     */
    public function runForTenant(int $tenantId, ?\App\Services\Detection\RunScope $scope = null, bool $aggregateOnly = false): void
    {
        // Set per call (each call overwrites), so a subsequent run on a reused
        // instance is never accidentally scoped. null scope = full scan.
        $this->scope         = $scope;
        $this->aggregateOnly = $aggregateOnly;

        AnomalySetting::seedForTenant($tenantId);

        // Ordering guard — never flag from a half-loaded tenant (the Midan
        // stale-run that produced ~2,000 phantom/dead investigations). Applies in
        // every mode. Bounded in time (WP1.2 / audit C3): an abandoned upload can
        // no longer switch detection off indefinitely — see pendingImportBlock().
        $this->lastRunDeferred = false;
        if (($reason = $this->pendingImportBlock($tenantId)) !== null) {
            $this->lastRunDeferred = true;
            Log::warning("[detect] tenant {$tenantId}: deferring detection — {$reason}.");
            return;
        }
        // Headroom for large tenants; the detectors below are written to stream,
        // so this is a safety margin, not a crutch.
        @ini_set('memory_limit', '768M');
        // Tenant currency (display-only): every money figure in a description is
        // labelled with it, so alerts read "AED 4,000 at risk" not "$4,000".
        $this->currency = Money::normalize(
            DB::table('tenants')->where('id', $tenantId)->value('currency')
        );
        $this->v2 = $this->forcedV2 ?? self::rulesV2For($tenantId);
        $this->suppressedByRule = [];
        // Set the analysis clock BEFORE any priming — every window below measures
        // "recent" relative to the latest data date, not wall-clock today.
        // v2 (audit H18): one clock per dataset; the shared clock is the sales one.
        if ($this->v2) {
            $this->resolveClocks($tenantId);
            $this->asOf = $this->clock('sales');
        } else {
            $this->resolveAsOf($tenantId);
        }
        $this->primePriceMap($tenantId);

        $settings = AnomalySetting::where('tenant_id', $tenantId)
            ->get()
            ->keyBy('rule_type');

        $t = fn (string $rule) => $settings->get($rule)?->getEffectiveThresholds() ?? [];

        // Prime the shared inventory snapshot + recent-demand maps ONCE. Every
        // demand-aware inventory rule (stockout, phantom, negative, overstock,
        // safety stock) reads these instead of re-scanning the full inventory
        // history and re-aggregating sales_daily itself — the single biggest win
        // for run time on large tenants.
        $demandWindow = (int) max(
            $t('stockout_risk')['days']       ?? 30,
            $t('phantom_inventory')['days']   ?? 30,
            $t('overstock')['lookback_days']  ?? 30,
        );
        if ($this->v2) {
            $this->primeInventorySnapshotV2($tenantId);
            $this->primeRecentDemandV2($tenantId, $demandWindow);
        } else {
            $this->primeInventorySnapshot($tenantId);
            $this->primeRecentDemand($tenantId, $demandWindow);
        }
        $this->primeReplenishment($tenantId); // B4: derived reorder points (after snapshot)
        $this->primeSkuSegments($tenantId);
        $this->primeCostCoverage($tenantId);  // R2c: cost_spike evaluability signal

        $rules = [
            // Demand & Sales
            'sales_spike'                => fn () => $this->detectSalesSpike($tenantId, $t('sales_spike')),
            'sales_drop'                 => fn () => $this->detectSalesDrop($tenantId, $t('sales_drop')),
            'demand_seasonality_breach'  => fn () => $this->detectDemandSeasonalityBreach($tenantId, $t('demand_seasonality_breach')),
            'cannibalization_signal'     => fn () => $this->detectCannibalizationSignal($tenantId, $t('cannibalization_signal')),
            'return_rate_spike'          => fn () => $this->detectReturnRateSpike($tenantId, $t('return_rate_spike')),
            'channel_mix_shift'          => fn () => $this->detectChannelMixShift($tenantId, $t('channel_mix_shift')),
            'demand_erosion'             => fn () => $this->detectDemandErosion($tenantId, $t('demand_erosion')),
            'demand_forecast_break'      => fn () => $this->detectDemandForecastBreak($tenantId, $t('demand_forecast_break')),
            'plan_variance'              => fn () => $this->detectPlanVariance($tenantId, $t('plan_variance')),

            // Inventory & Supply
            'stockout_risk'              => fn () => $this->detectStockoutRisk($tenantId, $t('stockout_risk')),
            'safety_stock_breach'        => fn () => $this->detectSafetyStockBreach($tenantId),
            'dead_stock'                 => fn () => $this->detectDeadStock($tenantId, $t('dead_stock')),
            'phantom_inventory'          => fn () => $this->detectPhantomInventory($tenantId, $t('phantom_inventory')),
            'negative_inventory'         => fn () => $this->detectNegativeInventory($tenantId),
            'overstock'                  => fn () => $this->detectOverstock($tenantId, $t('overstock')),
            'multi_location_imbalance'   => fn () => $this->detectMultiLocationImbalance($tenantId),
            'reorder_point_staleness'    => fn () => $this->detectReorderPointStaleness($tenantId, $t('reorder_point_staleness')),
            'inventory_shrinkage'        => fn () => $this->detectInventoryShrinkage($tenantId, $t('inventory_shrinkage')),
            'cumulative_shrink'          => fn () => $this->detectCumulativeShrink($tenantId, $t('cumulative_shrink')),

            // Purchase Orders
            'order_plan_variance'        => fn () => $this->detectOrderPlanVariance($tenantId, $t('order_plan_variance')),
            'po_overdue'                 => fn () => $this->detectPoOverdue($tenantId),
            'receiving_discrepancy'      => fn () => $this->detectReceivingDiscrepancy($tenantId, $t('receiving_discrepancy')),
            'po_late_receipt'            => fn () => $this->detectPoLateReceipt($tenantId, $t('po_late_receipt')),
            'supplier_fill_rate'         => fn () => $this->detectSupplierFillRate($tenantId, $t('supplier_fill_rate')),
            'supplier_lead_time_drift'   => fn () => $this->detectSupplierLeadTimeDrift($tenantId, $t('supplier_lead_time_drift')),
            'cost_spike'                 => fn () => $this->detectCostSpike($tenantId, $t('cost_spike')),

            // Financial
            'price_anomaly'              => fn () => $this->detectPriceAnomaly($tenantId, $t('price_anomaly')),
            'margin_erosion'             => fn () => $this->detectMarginErosion($tenantId),
            'discount_signal'            => fn () => $this->detectDiscountSignal($tenantId),
            'revenue_concentration_risk' => fn () => $this->detectRevenueConcentrationRisk($tenantId, $t('revenue_concentration_risk')),
            'slow_moving_capital'        => fn () => $this->detectSlowMovingCapital($tenantId, $t('slow_moving_capital')),

            // Store Performance
            'store_outlier'              => fn () => $this->detectStoreOutlier($tenantId, $t('store_outlier')),

            // Operational / Data Quality
            'import_frequency_gap'       => fn () => $this->detectImportFrequencyGap($tenantId, $t('import_frequency_gap')),
            'duplicate_transaction_ids'  => fn () => $this->detectDuplicateTransactionIds($tenantId),
            'sku_master_drift'           => fn () => $this->detectSkuMasterDrift($tenantId),
            'location_proliferation'     => fn () => $this->detectLocationProliferation($tenantId, $t('location_proliferation')),
        ];

        if ($this->v2) {
            $rules = array_merge($rules, $this->v2Rules($tenantId, $t)); // same keys → same order, v2 detectors
        }

        // Recovery lifecycle (R2): the reconciler replaces the old destructive
        // stale-sweep. A subject that stops failing is now advanced through
        // clearing → resolved (so recovery can be MEASURED) instead of deleted.
        $reconciler = new LifecycleReconciler();
        [$invPairs, $invSkus, $demPairs, $demSkus, $costSkus] = $this->buildCoverageSets();
        $confirmRunsFor = $this->confirmRunsResolver();

        // Data-quality readiness contract: skip rules whose dataset's latest batch is
        // RED (blocked by the firewall) — granular per dataset, so a bad inventory feed
        // never silences healthy sales rules. Off by default (behaviour-safe); flip
        // data_quality.readiness_enforcement to enable. Detection logic is untouched.
        $blockedRules = config('data_quality.readiness_enforcement', false)
            ? app(\App\Services\DataQuality\DataReadinessService::class)->blockedRules($tenantId)
            : [];

        foreach ($rules as $ruleType => $detector) {
            $setting = $settings->get($ruleType);
            if (!$setting || !$setting->enabled) {
                continue;
            }

            // Readiness gate — its input dataset is blocked this run.
            if (isset($blockedRules[$ruleType])) {
                continue;
            }

            // Incremental mode runs only the SKU-scoped rule families (Slice 2);
            // every other rule is left to the aggregate/full run.
            if ($this->scope !== null && ! in_array($ruleType, self::INCREMENTAL_RULES, true)) {
                continue;
            }

            // Aggregate mode (Slice 4) runs the complement — only the rules the
            // per-key incremental run skips — on a full scan. v2 (WP4.5, audit
            // M20): rules that fire on ABSENCE (a SKU that stopped selling writes
            // no new rows, so it is never "dirty") also run here, on everything.
            if ($this->aggregateOnly && in_array($ruleType, self::INCREMENTAL_RULES, true)
                && ! ($this->v2 && in_array($ruleType, self::ABSENCE_RULES, true))) {
                continue;
            }

            $this->touchedAnomalyIds = [];
            $succeeded = false;

            try {
                $detector();
                $succeeded = true;
            } catch (\Throwable $e) {
                Log::error("Anomaly detection failed [{$ruleType}]", [
                    'tenant_id' => $tenantId,
                    'error'     => $e->getMessage(),
                    'trace'     => $e->getTraceAsString(),
                ]);
            }

            // Only reconcile if the rule ran without errors — a thrown detector
            // must never let its open anomalies drift toward false recovery.
            // A dry run (capture) writes nothing, so it reconciles nothing.
            if ($succeeded && $this->capture === null) {
                $reconciler->reconcileRule(
                    $tenantId,
                    $ruleType,
                    $this->touchedAnomalyIds,
                    $this->evaluabilityFor($ruleType, $invPairs, $invSkus, $demPairs, $demSkus, $costSkus),
                    $confirmRunsFor,
                );
            }
        }

        if ($this->gatingActive) {
            Log::info('Anomaly detection: best-fit gating active', [
                'tenant_id'    => $tenantId,
                'profiles'     => count($this->skuSegments),
                'gated_flags'  => $this->gatedFlags,
            ]);
        }
    }

    // =========================================================================
    // DEMAND & SALES
    // =========================================================================

    private function detectSalesSpike(int $tenantId, array $thresholds): void
    {
        $pct        = (float)($thresholds['pct'] ?? 50);
        $days       = (int)($thresholds['days'] ?? 7);
        $minRevenue = (float)($thresholds['min_revenue'] ?? self::DEFAULT_MIN_REVENUE);

        [$recent, $historical, $periods] = $this->salesComparison($tenantId, $days);

        foreach ($recent as $sku => $recentQty) {
            $histQty = (float)($historical->get($sku) ?? 0);
            if ($histQty <= 0) continue;

            $avgQty = $histQty / $periods;

            // Adaptive: use z-score when a baseline exists; fall back to fixed-pct
            $baseline = $this->baselines->getBaseline($tenantId, $sku, 'sales_spike', 'daily_sales_qty');
            if ($baseline) {
                $dailyRecent = $recentQty / max(1, $days);
                $z = $this->baselines->zScore($dailyRecent, $baseline);
                if ($z <= $baseline->sensitivity_multiplier) continue;

                $excessUnits = max(0, $dailyRecent - $baseline->baseline_mean) * $days;
                $price       = $this->unitPrice($sku);
                $impact      = $excessUnits * $price;
                // Money floor only applies when we actually know the price — a SKU
                // with no price data is never silently suppressed.
                if ($price > 0 && $impact < $minRevenue) continue;
                $severity    = $price > 0 ? $this->severityFromImpact($impact) : Anomaly::SEVERITY_LOW;

                $this->flag($tenantId, 'sales_spike', $severity, $sku, null, null,
                    "SKU {$sku} daily sales rate (" . round($dailyRecent, 1) . " units/day) is "
                    . round($z, 1) . " standard deviations above the 90-day baseline mean of "
                    . round($baseline->baseline_mean, 1) . " units/day.",
                    ['recent_daily' => round($dailyRecent, 2), 'baseline_mean' => round($baseline->baseline_mean, 2),
                     'baseline_stddev' => round($baseline->baseline_stddev, 2), 'z_score' => round($z, 2),
                     'sensitivity' => $baseline->sensitivity_multiplier, 'days' => $days,
                     'revenue_impact' => round($impact, 2)]
                );
            } else {
                $changePct = (($recentQty - $avgQty) / $avgQty) * 100;
                if ($changePct < $pct) continue;

                $price  = $this->unitPrice($sku);
                $impact = ($recentQty - $avgQty) * $price;
                if ($price > 0 && $impact < $minRevenue) continue;
                $severity = $price > 0 ? $this->severityFromImpact($impact) : Anomaly::SEVERITY_LOW;

                $this->flag($tenantId, 'sales_spike', $severity, $sku, null, null,
                    "SKU {$sku} sales spiked " . round($changePct) . "% above its {$days}-day average "
                    . "(recent: " . round($recentQty) . " units, avg: " . round($avgQty) . " units).",
                    ['recent_qty' => $recentQty, 'avg_qty' => round($avgQty, 2), 'change_pct' => round($changePct, 1),
                     'days' => $days, 'revenue_impact' => round($impact, 2)]
                );
            }
        }

        // Optional store-level pass (off unless the tenant enables `store_level`).
        if ($thresholds['store_level'] ?? false) {
            $this->storeSalesSwing($tenantId, 'sales_spike', $thresholds, false);
        }
    }

    private function detectSalesDrop(int $tenantId, array $thresholds): void
    {
        $pct        = (float)($thresholds['pct'] ?? 30);
        $days       = (int)($thresholds['days'] ?? 7);
        $minRevenue = (float)($thresholds['min_revenue'] ?? self::DEFAULT_MIN_REVENUE);

        [$recent, $historical, $periods] = $this->salesComparison($tenantId, $days);

        foreach ($historical->keys() as $sku) {
            $histQty   = (float)$historical->get($sku);
            $recentQty = (float)($recent->get($sku) ?? 0);
            if ($histQty <= 0) continue;

            $avgQty = $histQty / $periods;

            // Adaptive: use z-score when a baseline exists; fall back to fixed-pct
            $baseline = $this->baselines->getBaseline($tenantId, $sku, 'sales_drop', 'daily_sales_qty');
            if ($baseline) {
                $dailyRecent = $recentQty / max(1, $days);
                // For a drop, z = (mean - value) / stddev — positive when below mean
                $z = ($baseline->baseline_mean - $dailyRecent) / max(0.001, $baseline->baseline_stddev);
                if ($z <= $baseline->sensitivity_multiplier) continue;

                $lostUnits = max(0, $baseline->baseline_mean - $dailyRecent) * $days;
                $price     = $this->unitPrice($sku);
                $impact    = $lostUnits * $price;
                if ($price > 0 && $impact < $minRevenue) continue;
                $severity  = $price > 0 ? $this->severityFromImpact($impact) : Anomaly::SEVERITY_MEDIUM;

                $this->flag($tenantId, 'sales_drop', $severity, $sku, null, null,
                    "SKU {$sku} daily sales rate (" . round($dailyRecent, 1) . " units/day) is "
                    . round($z, 1) . " standard deviations below the 90-day baseline mean of "
                    . round($baseline->baseline_mean, 1) . " units/day.",
                    ['recent_daily' => round($dailyRecent, 2), 'baseline_mean' => round($baseline->baseline_mean, 2),
                     'baseline_stddev' => round($baseline->baseline_stddev, 2), 'z_score' => round($z, 2),
                     'sensitivity' => $baseline->sensitivity_multiplier, 'days' => $days,
                     'revenue_impact' => round($impact, 2)]
                );
            } else {
                $changePct = (($avgQty - $recentQty) / $avgQty) * 100;
                if ($changePct < $pct) continue;

                $price  = $this->unitPrice($sku);
                $impact = ($avgQty - $recentQty) * $price;
                if ($price > 0 && $impact < $minRevenue) continue;
                $severity = $price > 0 ? $this->severityFromImpact($impact) : Anomaly::SEVERITY_MEDIUM;

                $this->flag($tenantId, 'sales_drop', $severity, $sku, null, null,
                    "SKU {$sku} sales dropped " . round($changePct) . "% below its {$days}-day average "
                    . "(recent: " . round($recentQty) . " units, avg: " . round($avgQty) . " units).",
                    ['recent_qty' => $recentQty, 'avg_qty' => round($avgQty, 2), 'change_pct' => round($changePct, 1),
                     'days' => $days, 'revenue_impact' => round($impact, 2)]
                );
            }
        }

        // Optional store-level pass (off unless the tenant enables `store_level`).
        if ($thresholds['store_level'] ?? false) {
            $this->storeSalesSwing($tenantId, 'sales_drop', $thresholds, true);
        }
    }

    /**
     * Seasonality breach — recent demand departs from what the calendar predicts.
     *
     * PRIMARY (≥ ~1 year of history): year-over-year — compare the last 30 days to
     * the same 30 days last year. This is the strongest signal but needs a year of
     * data. With less, `prior` is empty and YoY can't run.
     *
     * FALLBACK (< 1 year): a seasonal-ADJUSTED recent-vs-baseline. It takes each
     * SKU's own baseline daily rate, projects the expected units for the recent
     * window using the chain's day-of-week factors (via SeasonalityService), and
     * flags a material departure from that expectation. This keeps the rule alive
     * on short histories and grows into full seasonal power as data accumulates.
     */
    private function detectDemandSeasonalityBreach(int $tenantId, array $thresholds): void
    {
        $pct        = (float)($thresholds['pct'] ?? 40);
        $minRevenue = (float)($thresholds['min_revenue'] ?? self::TREND_MIN_REVENUE);

        $currentEnd   = $this->analysisDate()->format('Y-m-d');
        $currentStart = $this->analysisDate()->subDays(30)->format('Y-m-d');
        $priorEnd     = $this->analysisDate()->subYear()->format('Y-m-d');
        $priorStart   = $this->analysisDate()->subYear()->subDays(30)->format('Y-m-d');

        $current = SalesTransaction::where('tenant_id', $tenantId)
            ->whereBetween('date', [$currentStart, $currentEnd])
            ->selectRaw('sku, SUM(quantity) as qty')
            ->groupBy('sku')
            ->pluck('qty', 'sku')
            ->map(fn ($v) => (float) $v);

        $prior = SalesTransaction::where('tenant_id', $tenantId)
            ->whereBetween('date', [$priorStart, $priorEnd])
            ->selectRaw('sku, SUM(quantity) as qty')
            ->groupBy('sku')
            ->pluck('qty', 'sku')
            ->map(fn ($v) => (float) $v);

        // ── PRIMARY: year-over-year (needs a year of history) ────────────────
        if ($prior->isNotEmpty()) {
            foreach ($current as $sku => $currentQty) {
                $priorQty = (float)($prior->get($sku) ?? 0);
                if ($priorQty <= 0) continue;

                $changePct = abs(($currentQty - $priorQty) / $priorQty) * 100;
                if ($changePct < $pct) continue;

                $price  = $this->unitPrice($sku);
                $impact = abs($currentQty - $priorQty) * $price;
                if ($price > 0 && $impact < $minRevenue) continue;

                $direction = $currentQty > $priorQty ? 'above' : 'below';
                $product   = Product::where('tenant_id', $tenantId)->where('sku', $sku)->first();

                $this->flag($tenantId, 'demand_seasonality_breach', 'medium', $sku, null, $product?->id,
                    "SKU {$sku} sold " . round($currentQty) . " units in the last 30 days — "
                    . round($changePct) . "% {$direction} the same window last year (" . round($priorQty) . " units).",
                    ['mode' => 'yoy', 'current_qty' => $currentQty, 'prior_year_qty' => $priorQty,
                     'change_pct' => round($changePct, 1), 'direction' => $direction, 'revenue_impact' => round($impact, 2)]
                );
            }
            return;
        }

        // ── FALLBACK: seasonal-adjusted recent-vs-baseline (short history) ───
        $seasonality = new SeasonalityService();
        $dowFactors  = $seasonality->dayOfWeekFactors($tenantId, 90);

        $recentDays   = 7;
        $baselineDays = 28;
        $recentFrom   = $this->analysisDate()->subDays($recentDays)->format('Y-m-d');
        $baseFrom     = $this->analysisDate()->subDays($recentDays + $baselineDays)->format('Y-m-d');
        $baseTo       = $recentFrom;

        // Recent dates (for day-of-week projection).
        $recentDates = [];
        for ($i = 1; $i <= $recentDays; $i++) {
            $recentDates[] = $this->analysisDate()->subDays($i)->format('Y-m-d');
        }

        $recent = DB::table('sales_daily')->where('tenant_id', $tenantId)
            ->where('date', '>=', $recentFrom)
            ->selectRaw('sku, SUM(units_sold) as u')->groupBy('sku')
            ->pluck('u', 'sku');

        DB::table('sales_daily')->where('tenant_id', $tenantId)
            ->whereBetween('date', [$baseFrom, $baseTo])
            ->selectRaw('sku, SUM(units_sold) as u')->groupBy('sku')
            ->cursor()
            ->each(function ($row) use ($tenantId, $recent, $dowFactors, $recentDates, $recentDays, $baselineDays, $pct, $minRevenue, $seasonality) {
                $baselineDaily = (float) $row->u / max(1, $baselineDays);
                if ($baselineDaily <= 0) return;

                $expected  = $seasonality->expectedUnits($baselineDaily, $dowFactors, $recentDates);
                if ($expected <= 0) return;
                $actual    = (float) ($recent[$row->sku] ?? 0);
                $deviation = abs($actual - $expected) / $expected * 100;
                if ($deviation < $pct) return;

                $price  = $this->unitPrice($row->sku);
                $impact = abs($actual - $expected) * $price;
                if ($price > 0 && $impact < $minRevenue) return;

                $direction = $actual > $expected ? 'above' : 'below';
                $product   = Product::where('tenant_id', $tenantId)->where('sku', $row->sku)->first();

                $this->flag($tenantId, 'demand_seasonality_breach', 'medium', $row->sku, null, $product?->id,
                    "SKU {$row->sku} sold " . round($actual) . " units in the last {$recentDays} days — "
                    . round($deviation) . "% {$direction} its calendar-adjusted expectation of " . round($expected) . " units.",
                    ['mode' => 'seasonal_adjusted', 'actual' => round($actual, 1), 'expected' => round($expected, 1),
                     'deviation_pct' => round($deviation, 1), 'direction' => $direction, 'revenue_impact' => round($impact, 2)]
                );
            });
    }

    /**
     * Store-level, candidate-based cannibalization detection (reads the
     * sales_daily aggregate, not raw POS). The pipeline narrows the population
     * before any comparison, so work scales with genuinely-moving SKUs rather
     * than SKU²:
     *   store → category → positive-candidate SKUs (rose ≥ threshold) →
     *   down-moving siblings in the SAME store+category → category-demand guard
     *   → category-share scoring → one signal per (store, affected SKU).
     */
    private function detectCannibalizationSignal(int $tenantId, array $thresholds): void
    {
        $pct           = (float)($thresholds['pct'] ?? 30);
        $days          = (int)($thresholds['days'] ?? 30);
        $minUnits      = (float)($thresholds['min_units'] ?? 5);
        $minConfidence = (float)($thresholds['min_confidence'] ?? 0.5);
        $minValue      = (float)($thresholds['min_revenue'] ?? self::DEFAULT_MIN_REVENUE);

        $recentFrom   = $this->analysisDate()->subDays($days)->format('Y-m-d');
        $baselineFrom = $this->analysisDate()->subDays($days * 2)->format('Y-m-d');

        // SKU → [category, product_id]; only categorised products can have siblings.
        $catalog = [];
        Product::where('tenant_id', $tenantId)
            ->whereNotNull('category')
            ->select(['sku', 'category', 'id'])
            ->cursor()
            ->each(function ($p) use (&$catalog) {
                $catalog[trim((string) $p->sku)] = ['category' => $p->category, 'product_id' => $p->id];
            });

        if (empty($catalog)) return;

        // Evaluate store by store (batching) so nothing large is held at once.
        $storeIds = DB::table('sales_daily')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('store_id')
            ->where('date', '>=', $baselineFrom)
            ->distinct()
            ->pluck('store_id');

        foreach ($storeIds as $storeId) {
            $this->detectCannibalizationForStore($tenantId, (int) $storeId, $catalog, $pct, $minUnits, $days, $recentFrom, $baselineFrom, $minConfidence, $minValue);
        }
    }

    /** Minimum category-share loss (percentage points) for a faller to count as a real victim. */
    private const CANNIBALIZATION_MIN_SHARE_LOSS = 3.0;

    private function detectCannibalizationForStore(
        int $tenantId, int $storeId, array $catalog,
        float $pct, float $minUnits, int $days, string $recentFrom, string $baselineFrom,
        float $minConfidence, float $minValue
    ): void {
        // Recent vs equal-length prior window, per SKU, for this store only.
        $rows = DB::select(
            'SELECT sku,
                    SUM(CASE WHEN date >= ? THEN units_sold ELSE 0 END) AS recent,
                    SUM(CASE WHEN date >= ? AND date < ? THEN units_sold ELSE 0 END) AS baseline
             FROM sales_daily
             WHERE tenant_id = ? AND store_id = ? AND date >= ?
             GROUP BY sku',
            [$recentFrom, $baselineFrom, $recentFrom, $tenantId, $storeId, $baselineFrom]
        );

        // Bucket the store's SKUs by category, tracking category totals.
        $cats = []; // category => ['skus'=>[...], 'catRecent'=>, 'catBaseline'=>]
        foreach ($rows as $r) {
            $sku = trim((string) $r->sku);
            if (! isset($catalog[$sku])) continue; // uncategorised → no siblings

            $category = $catalog[$sku]['category'];
            $recent   = (float) $r->recent;
            $baseline = (float) $r->baseline;

            if (! isset($cats[$category])) {
                $cats[$category] = ['skus' => [], 'catRecent' => 0.0, 'catBaseline' => 0.0];
            }
            $cats[$category]['catRecent']   += $recent;
            $cats[$category]['catBaseline'] += $baseline;
            $cats[$category]['skus'][] = [
                'sku'        => $sku,
                'recent'     => $recent,
                'baseline'   => $baseline,
                'change'     => $baseline > 0 ? (($recent - $baseline) / $baseline) * 100 : null,
                'product_id' => $catalog[$sku]['product_id'],
            ];
        }

        foreach ($cats as $category => $c) {
            if (count($c['skus']) < 2 || $c['catBaseline'] <= 0) continue;

            $catChangePct = (($c['catRecent'] - $c['catBaseline']) / $c['catBaseline']) * 100;

            // Category-demand guard: if the whole category is collapsing, this is
            // category decline, not cannibalization — do not flag.
            if ($catChangePct <= -$pct) continue;

            // Candidate risers and sibling fallers within this store+category.
            $candidates = array_filter($c['skus'], fn ($s) => $s['change'] !== null && $s['change'] >= $pct && $s['recent'] >= $minUnits);
            $fallers    = array_filter($c['skus'], fn ($s) => $s['change'] !== null && $s['change'] <= -$pct);

            if (empty($candidates) || empty($fallers)) continue;

            // Strongest riser is the primary suspect.
            usort($candidates, fn ($a, $b) => $b['change'] <=> $a['change']);
            $primary = $candidates[0];

            $primaryRecentShare   = $c['catRecent']   > 0 ? $primary['recent']   / $c['catRecent']   : 0;
            $primaryBaselineShare = $c['catBaseline'] > 0 ? $primary['baseline'] / $c['catBaseline'] : 0;
            $primaryShareGain     = ($primaryRecentShare - $primaryBaselineShare) * 100;

            foreach ($fallers as $affected) {
                $affRecentShare   = $c['catRecent']   > 0 ? $affected['recent']   / $c['catRecent']   : 0;
                $affBaselineShare = $c['catBaseline'] > 0 ? $affected['baseline'] / $c['catBaseline'] : 0;
                $affShareLoss     = ($affBaselineShare - $affRecentShare) * 100; // positive = lost share

                // Confidence: category stability + genuine share transfer.
                $stability   = max(0.0, 1 - min(1.0, abs($catChangePct) / max(1.0, 2 * $pct)));
                $shareFactor = min(1.0, max(0.0, ($primaryShareGain + $affShareLoss)) / max(1.0, 2 * $pct));
                $confidence  = round(0.5 * $stability + 0.5 * $shareFactor, 2);

                // Gate hard so only material, confident cannibalization surfaces — a
                // down-mover in the same category is NOT a signal unless it actually
                // ceded meaningful share to the riser and the lost sales matter.
                $revenueImpact = ($affected['baseline'] - $affected['recent']) * $this->unitPrice($affected['sku']);
                if ($affShareLoss < self::CANNIBALIZATION_MIN_SHARE_LOSS) continue;
                if ($confidence < $minConfidence) continue;
                if ($this->unitPrice($affected['sku']) > 0 && $revenueImpact < $minValue) continue;

                $severity = $confidence >= 0.7 ? Anomaly::SEVERITY_HIGH
                    : ($confidence >= 0.4 ? Anomaly::SEVERITY_MEDIUM : Anomaly::SEVERITY_LOW);

                $this->flag($tenantId, 'cannibalization_signal', $severity, $affected['sku'], $storeId, $affected['product_id'],
                    "SKU {$affected['sku']} fell " . round(abs($affected['change'])) . "% while sibling {$primary['sku']} rose "
                    . round($primary['change']) . "% in the same store/category ({$category}); category demand moved "
                    . round($catChangePct, 1) . "% and {$primary['sku']} took category share — possible cannibalization.",
                    [
                        'category'                  => $category,
                        'primary_sku'               => $primary['sku'],
                        'affected_sku'              => $affected['sku'],
                        'primary_sales_change_pct'  => round($primary['change'], 1),
                        'affected_sales_change_pct' => round($affected['change'], 1),
                        'category_sales_change_pct' => round($catChangePct, 1),
                        'primary_share_change_pct'  => round($primaryShareGain, 1),
                        'affected_share_change_pct' => round(-$affShareLoss, 1),
                        'confidence_score'          => $confidence,
                        'promotion_context'         => 'unknown',
                        'lookback_days'             => $days,
                        'revenue_impact'            => round(max(0.0, $revenueImpact), 2),
                    ]
                );
            }
        }
    }

    private function detectReturnRateSpike(int $tenantId, array $thresholds): void
    {
        $pct   = (float)($thresholds['pct'] ?? 15);
        $days  = (int)($thresholds['days'] ?? 30);
        $since = $this->analysisDate()->subDays($days)->format('Y-m-d');

        $txsBySku = SalesTransaction::where('tenant_id', $tenantId)
            ->where('date', '>=', $since)
            ->get()
            ->groupBy('sku');

        // Dedicated returns dataset (if the tenant imports returns as their own
        // data type). Counted in addition to legacy negative-quantity sales rows.
        $returnsBySku = \App\Models\SalesReturn::where('tenant_id', $tenantId)
            ->where('date', '>=', $since)
            ->get()
            ->groupBy('sku');

        $allSkus = collect($txsBySku->keys())->merge($returnsBySku->keys())->unique();

        foreach ($allSkus as $sku) {
            $skuTxs  = $txsBySku->get($sku) ?? collect();
            $sales   = (float) $skuTxs->where('quantity', '>', 0)->sum('quantity');
            $returns = (float) abs($skuTxs->where('quantity', '<', 0)->sum('quantity'));
            $returns += (float) (optional($returnsBySku->get($sku))->sum('quantity') ?? 0);

            if ($sales <= 0 || $returns <= 0) continue;

            $returnRate = ($returns / ($sales + $returns)) * 100;

            if ($returnRate >= $pct) {
                $product = Product::where('tenant_id', $tenantId)->where('sku', $sku)->first();
                $this->flag($tenantId, 'return_rate_spike', 'medium', $sku, null, $product?->id,
                    "SKU {$sku} has a " . round($returnRate, 1) . "% return rate over the last {$days} days "
                    . "(" . round($returns) . " units returned vs " . round($sales) . " units sold).",
                    ['sales_qty' => $sales, 'returns_qty' => $returns, 'return_rate_pct' => round($returnRate, 1), 'days' => $days]
                );
            }
        }
    }

    private function detectChannelMixShift(int $tenantId, array $thresholds): void
    {
        // `pct` is now a RELATIVE change in a location's sales share, not absolute
        // percentage points. With ~50 stores no location holds more than a few
        // percent of chain sales, so an absolute-points test (old 25pp) could
        // never fire; a location halving its share (2.0% → 1.0%) is the real
        // signal. `min_units` keeps tiny stores from tripping on noise.
        $pct      = (float)($thresholds['pct'] ?? 25);
        $days     = (int)($thresholds['days'] ?? 30);
        $minUnits = (float)($thresholds['min_units'] ?? 200);

        $recentStart = $this->analysisDate()->subDays($days)->format('Y-m-d');
        $priorStart  = $this->analysisDate()->subDays($days * 2)->format('Y-m-d');
        $priorEnd    = $this->analysisDate()->subDays($days)->format('Y-m-d');

        $recentByLoc = SalesTransaction::where('tenant_id', $tenantId)
            ->where('date', '>=', $recentStart)
            ->whereNotNull('location')
            ->selectRaw('location, SUM(quantity) as qty')
            ->groupBy('location')
            ->pluck('qty', 'location')
            ->map(fn ($v) => (float) $v);

        $priorByLoc = SalesTransaction::where('tenant_id', $tenantId)
            ->whereBetween('date', [$priorStart, $priorEnd])
            ->whereNotNull('location')
            ->selectRaw('location, SUM(quantity) as qty')
            ->groupBy('location')
            ->pluck('qty', 'location')
            ->map(fn ($v) => (float) $v);

        $recentTotal = $recentByLoc->sum();
        $priorTotal  = $priorByLoc->sum();

        if ($recentTotal <= 0 || $priorTotal <= 0) return;

        foreach ($recentByLoc as $location => $recentQty) {
            $priorQty = (float)($priorByLoc->get($location) ?? 0);
            if ($priorQty <= 0) continue;
            if (($recentQty + $priorQty) < $minUnits) continue; // immaterial location

            $recentShare = ($recentQty / $recentTotal) * 100;
            $priorShare  = ($priorQty / $priorTotal) * 100;
            $shift       = $recentShare - $priorShare;                 // percentage points
            $relChange   = (abs($shift) / max(0.0001, $priorShare)) * 100; // relative %

            // Relative move must clear the threshold AND the absolute points move
            // must be non-trivial (guards a 0.02%→0.04% "doubling" on a tiny share).
            if ($relChange >= $pct && abs($shift) >= 0.5) {
                $direction = $shift > 0 ? 'gained' : 'lost';
                $this->flag($tenantId, 'channel_mix_shift', 'medium', null, null, null,
                    "Location '{$location}' {$direction} " . round($relChange) . "% of its sales share "
                    . "(now " . round($recentShare, 1) . "% vs prior " . round($priorShare, 1) . "% of chain sales).",
                    ['location' => $location, 'recent_share_pct' => round($recentShare, 1), 'prior_share_pct' => round($priorShare, 1),
                     'shift_pct' => round($shift, 1), 'relative_change_pct' => round($relChange, 1)]
                );
            }
        }
    }

    /**
     * Slow demand erosion — a sustained downward TREND, not a sharp break.
     *
     * Recent-vs-window rules (sales_drop) compare a short recent window to a
     * historical one; a gradual slide moves BOTH windows down together, so the
     * ratio never trips. This fits a linear regression to daily units over the
     * window (per store+SKU, in SQL via regr_slope/regr_r2) and flags a
     * consistent negative slope whose fitted line falls by >= pct across the
     * window. regr_r2 guards against noise (a real trend, not a scatter).
     */
    private function detectDemandErosion(int $tenantId, array $thresholds): void
    {
        $days     = (int)($thresholds['days'] ?? 90);
        $pct      = (float)($thresholds['pct'] ?? 40);
        $minUnits = (float)($thresholds['min_units'] ?? 20);
        $minR2    = (float)($thresholds['min_r2'] ?? 0.3);
        $minValue = (float)($thresholds['min_revenue'] ?? self::TREND_MIN_REVENUE);

        $from = $this->analysisDate()->subDays($days)->format('Y-m-d');

        [$scopeSql, $scopeBind] = $this->scopeSql('sku');

        $rows = DB::select(
            "SELECT store_id, sku,
                    regr_slope(units_sold, EXTRACT(EPOCH FROM date)/86400.0)     AS slope,
                    regr_intercept(units_sold, EXTRACT(EPOCH FROM date)/86400.0) AS intercept,
                    regr_r2(units_sold, EXTRACT(EPOCH FROM date)/86400.0)        AS r2,
                    COUNT(*) AS pts, SUM(units_sold) AS total,
                    MIN(EXTRACT(EPOCH FROM date)/86400.0) AS x0,
                    MAX(EXTRACT(EPOCH FROM date)/86400.0) AS x1
             FROM sales_daily
             WHERE tenant_id = ? AND date >= ?{$scopeSql}
             GROUP BY store_id, sku
             HAVING COUNT(*) >= 8
                AND SUM(units_sold) >= ?
                AND regr_slope(units_sold, EXTRACT(EPOCH FROM date)/86400.0) < 0",
            array_merge([$tenantId, $from], $scopeBind, [$minUnits])
        );

        foreach ($rows as $r) {
            $r2 = $r->r2 !== null ? (float) $r->r2 : 0.0;
            if ($r2 < $minR2) continue;

            $slope = (float) $r->slope;
            $start = (float) $r->intercept + $slope * (float) $r->x0;
            $end   = (float) $r->intercept + $slope * (float) $r->x1;
            if ($start <= 0) continue;

            $declinePct = (($start - $end) / $start) * 100;
            if ($declinePct < $pct) continue;

            // Value = run-rate loss over a forward DECISION horizon if the decline
            // continues — the current daily deficit (how far below the window's
            // starting rate it now runs) projected over TREND_HORIZON_DAYS. This
            // is realistic and actionable ("~AED X/month if unaddressed"), not the
            // cumulative area over the whole window, which massively over-stated it.
            $dailyDeficit = max(0.0, $start - $end);
            $lostUnits    = $dailyDeficit * self::TREND_HORIZON_DAYS;
            $price        = $this->unitPrice($r->sku);
            $impact       = $lostUnits * $price;
            if ($price > 0 && $impact < $minValue) continue;
            $severity  = $price > 0 ? $this->severityFromImpact($impact) : Anomaly::SEVERITY_MEDIUM;

            // A chain-level sales_daily row has no store — never store 0 (FK).
            $this->flag($tenantId, 'demand_erosion', $severity, $r->sku, $r->store_id !== null ? (int) $r->store_id : null, null,
                "SKU {$r->sku} is in a sustained demand decline — down ~" . round($declinePct)
                . "% across the last {$days} days on a consistent downward trend (R²=" . round($r2, 2)
                . "). If it continues, roughly " . $this->money($impact) . " of sales is at risk over the next month.",
                [
                    'decline_pct'      => round($declinePct, 1),
                    'slope_per_day'    => round($slope, 4),
                    'r2'               => round($r2, 2),
                    'window_days'      => $days,
                    'total_units'      => round((float) $r->total, 1),
                    'horizon_days'     => self::TREND_HORIZON_DAYS,
                    'revenue_impact'   => round($impact, 2),
                ]
            );
        }
    }

    /**
     * Demand vs best-fit forecast (Phase 4+5) — the demand detector for
     * intermittent/lumpy/erratic SKUs, where fixed-% spike/drop rules don't fit.
     *
     * Per SKU (chain level), fits a Croston/SBA forecast over the baseline: the
     * smoothed demand SIZE (z) and the smoothed INTERVAL between demands (p) give
     * a per-day rate z/p, SBA-bias-corrected. That rate is projected onto the
     * recent window's calendar with day-of-week factors (Phase 4 seasonality), and
     * recent actual demand is compared to it. The tolerance band widens with the
     * item's own CV² (lumpy items need a bigger move to flag). Shortfalls only
     * fire when the item normally sells ≥2× in the window, so ordinary
     * intermittent gaps don't read as anomalies.
     */
    private function detectDemandForecastBreak(int $tenantId, array $thresholds): void
    {
        $basePct    = (float)($thresholds['pct'] ?? 50) / 100;
        $recentDays = (int)($thresholds['days'] ?? 7);
        $windowDays = (int)($thresholds['window'] ?? 90);
        $alpha      = (float)($thresholds['alpha'] ?? 0.2);
        $minOcc     = (int)($thresholds['min_occasions'] ?? 3);
        $minRevenue = (float)($thresholds['min_revenue'] ?? self::DEFAULT_MIN_REVENUE);

        // Chain-level profiles decide which SKUs this rule owns.
        $applicable = [SkuProfile::SEG_INTERMITTENT, SkuProfile::SEG_LUMPY, SkuProfile::SEG_ERRATIC];
        $prof = [];
        DB::table('sku_profiles')
            ->where('tenant_id', $tenantId)->where('store_id', 0)
            ->whereIn('segment', $applicable)
            ->when($this->scope, fn ($q) => $this->scope->constrain($q))
            ->select(['sku', 'segment', 'cv2'])
            ->cursor()
            ->each(function ($p) use (&$prof) {
                $prof[trim((string) $p->sku)] = ['segment' => $p->segment, 'cv2' => (float) ($p->cv2 ?? 0)];
            });
        if (empty($prof)) return;

        $seasonality = new SeasonalityService();
        $dow         = $seasonality->dayOfWeekFactors($tenantId, $windowDays);

        $recentFrom  = $this->analysisDate()->subDays($recentDays)->format('Y-m-d');
        $windowFrom  = $this->analysisDate()->subDays($windowDays)->format('Y-m-d');
        $recentDates = [];
        for ($i = 1; $i <= $recentDays; $i++) {
            $recentDates[] = $this->analysisDate()->subDays($i)->format('Y-m-d');
        }

        // Per-SKU streaming Croston: chain daily totals ordered by (sku, date).
        $curSku = null; $z = 0.0; $p = 0.0; $occ = 0; $lastDate = null; $recentActual = 0.0;

        $flush = function () use (
            &$curSku, &$z, &$p, &$occ, &$recentActual,
            $prof, $alpha, $minOcc, $recentDays, $basePct, $minRevenue, $dow, $recentDates, $seasonality, $tenantId
        ) {
            if ($curSku === null) return;
            $pr = $prof[$curSku] ?? null;

            if ($pr !== null && $occ >= $minOcc && $p > 0) {
                $rate     = ($z / $p) * (1 - $alpha / 2);          // SBA-corrected per-day rate
                $expected = $seasonality->expectedUnits($rate, $dow, $recentDates);

                if ($expected > 0) {
                    $effTol    = $basePct * (1 + $pr['cv2']);       // wider band for lumpier demand
                    $occasions = $recentDays / max(1e-9, $p);       // expected demand events in the window
                    $dev       = $recentActual - $expected;

                    $dir = null;
                    if ($recentActual > $expected * (1 + $effTol)) {
                        $dir = 'above';
                    } elseif ($occasions >= 2 && $recentActual < $expected * (1 - $effTol)) {
                        $dir = 'below';
                    }

                    if ($dir !== null) {
                        $price  = $this->unitPrice($curSku);
                        $impact = abs($dev) * $price;
                        if (! ($price > 0 && $impact < $minRevenue)) {
                            $sev    = $price > 0 ? $this->severityFromImpact($impact) : Anomaly::SEVERITY_MEDIUM;
                            $devPct = round(abs($dev) / $expected * 100);
                            $this->flag($tenantId, 'demand_forecast_break', $sev, $curSku, null, null,
                                "SKU {$curSku} sold " . round($recentActual) . " units in the last {$recentDays} days — "
                                . $devPct . "% {$dir} its best-fit ({$pr['segment']}) forecast of " . round($expected, 1) . " units.",
                                [
                                    'segment'        => $pr['segment'],
                                    'model'          => 'croston_sba',
                                    'forecast_units' => round($expected, 1),
                                    'actual_units'   => round($recentActual, 1),
                                    'deviation_pct'  => $devPct,
                                    'direction'      => $dir,
                                    'revenue_impact' => round($impact, 2),
                                ]
                            );
                        }
                    }
                }
            }

            $z = 0.0; $p = 0.0; $occ = 0; $recentActual = 0.0;
        };

        $rows = DB::table('sales_daily')
            ->where('tenant_id', $tenantId)
            ->where('date', '>=', $windowFrom)
            ->when($this->scope, fn ($q) => $this->scope->constrain($q))
            ->selectRaw("sku, TO_CHAR(date, 'YYYY-MM-DD') AS d, SUM(units_sold) AS u")
            ->groupBy('sku', 'date')
            ->orderBy('sku')->orderBy('date')
            ->cursor();

        foreach ($rows as $r) {
            $sku = trim((string) $r->sku);
            if ($sku !== $curSku) {
                $flush();
                $curSku = $sku; $lastDate = null;
            }
            if (! isset($prof[$sku])) continue; // only fit SKUs this rule owns

            $u = (float) $r->u;
            if ($r->d >= $recentFrom) {
                $recentActual += $u;
            } else {
                if ($occ === 0) {
                    $z = $u; $p = 1.0;
                } else {
                    $interval = max(1, (int) abs(Carbon::parse($r->d)->diffInDays(Carbon::parse($lastDate))));
                    $z = $alpha * $u + (1 - $alpha) * $z;
                    $p = $alpha * $interval + (1 - $alpha) * $p;
                }
                $occ++; $lastDate = $r->d;
            }
        }
        $flush();
    }

    /**
     * Actual-vs-PLAN variance. Where `detectDemandForecastBreak` measures reality
     * against Autnyx's OWN best-fit baseline, this measures it against the tenant's
     * INGESTED plan (`plan_forecasts`, populated by ForecastFeedIngestor from RELEX /
     * Blue Yonder / Slimstock / S4 MRP / D365 / Oracle Fusion). It turns detection
     * from "deviation vs our baseline" into "deviation vs YOUR plan" — the whole
     * point of ingesting the forecast.
     *
     * Method: for each plan point in the recent (already-elapsed) window, pair its
     * forecast_qty with that day's actual units, then aggregate the paired days per
     * (sku, store). Chain-level plan points (store_id NULL) compare against sales
     * summed across all stores; store-level points against that store. A day planned
     * but with no sale counts as actual 0 (a real miss). Only the freshest source
     * wins per (sku, store, day). Flags when the window variance breaches the
     * tolerance band AND the revenue impact clears the floor. No plan → no work.
     */
    private function detectPlanVariance(int $tenantId, array $thresholds): void
    {
        $tol        = (float) ($thresholds['pct'] ?? 30) / 100;   // tighter than best-fit: it's their own number
        $days       = (int) ($thresholds['days'] ?? 14);
        $minUnits   = (float) ($thresholds['min_units'] ?? 1);    // window-sum forecast floor (avoid div-by-noise)
        $minRevenue = (float) ($thresholds['min_revenue'] ?? self::DEFAULT_MIN_REVENUE);

        $to   = $this->analysisDate()->format('Y-m-d');
        $from = $this->analysisDate()->copy()->subDays($days - 1)->format('Y-m-d');

        [$scopeSql, $scopeBind] = $this->scopeSql('pf.sku');

        // ── Store-level plans: actual = that store's units for the matched day. ──
        $storeRows = DB::select(
            "SELECT sku, store_id,
                    SUM(forecast_qty) AS f, SUM(actual) AS a,
                    MAX(source) AS source, COUNT(*) AS matched_days
             FROM (
                 SELECT DISTINCT ON (pf.sku, pf.store_id, pf.target_date)
                        pf.sku, pf.store_id, pf.forecast_qty, pf.source,
                        COALESCE(sd.u, 0) AS actual
                 FROM plan_forecasts pf
                 LEFT JOIN (
                     SELECT sku, store_id, date, SUM(units_sold) AS u
                     FROM sales_daily
                     WHERE tenant_id = ? AND date BETWEEN ? AND ?
                     GROUP BY sku, store_id, date
                 ) sd ON sd.sku = pf.sku AND sd.store_id = pf.store_id AND sd.date = pf.target_date
                 WHERE pf.tenant_id = ? AND pf.store_id IS NOT NULL
                   AND pf.target_date BETWEEN ? AND ?{$scopeSql}
                 ORDER BY pf.sku, pf.store_id, pf.target_date, pf.updated_at DESC
             ) x
             GROUP BY sku, store_id",
            array_merge([$tenantId, $from, $to, $tenantId, $from, $to], $scopeBind),
        );

        // ── Chain-level plans (store_id NULL): actual = units across ALL stores. ──
        $chainRows = DB::select(
            "SELECT sku,
                    SUM(forecast_qty) AS f, SUM(actual) AS a,
                    MAX(source) AS source, COUNT(*) AS matched_days
             FROM (
                 SELECT DISTINCT ON (pf.sku, pf.target_date)
                        pf.sku, pf.forecast_qty, pf.source,
                        COALESCE(sd.u, 0) AS actual
                 FROM plan_forecasts pf
                 LEFT JOIN (
                     SELECT sku, date, SUM(units_sold) AS u
                     FROM sales_daily
                     WHERE tenant_id = ? AND date BETWEEN ? AND ?
                     GROUP BY sku, date
                 ) sd ON sd.sku = pf.sku AND sd.date = pf.target_date
                 WHERE pf.tenant_id = ? AND pf.store_id IS NULL
                   AND pf.target_date BETWEEN ? AND ?{$scopeSql}
                 ORDER BY pf.sku, pf.target_date, pf.updated_at DESC
             ) x
             GROUP BY sku",
            array_merge([$tenantId, $from, $to, $tenantId, $from, $to], $scopeBind),
        );

        if (empty($storeRows) && empty($chainRows)) {
            return; // tenant has no ingested plan for the window — nothing to measure
        }

        // Store labels only if we have store-level plans (small table, one query).
        // Build the label in PHP — a DB::raw() column can't be used as a pluck value.
        $storeLabels = [];
        if (! empty($storeRows)) {
            foreach (DB::table('stores')->where('tenant_id', $tenantId)->get(['id', 'code', 'name']) as $s) {
                $storeLabels[$s->id] = $s->code ?: ($s->name ?: "store {$s->id}");
            }
        }

        $emit = function (string $sku, ?int $storeId, float $f, float $a, ?string $source, int $matchedDays)
            use ($tenantId, $tol, $minUnits, $minRevenue, $storeLabels, $days): void {
            if ($f < $minUnits || $f <= 0) {
                return; // no meaningful plan to measure against
            }

            $dev = $a - $f;
            $dir = null;
            if ($a > $f * (1 + $tol)) {
                $dir = 'above';
            } elseif ($a < $f * (1 - $tol)) {
                $dir = 'below';
            }
            if ($dir === null) {
                return; // actuals are tracking the plan within tolerance
            }

            $price  = $this->unitPrice($sku);
            $impact = abs($dev) * $price;
            if ($price > 0 && $impact < $minRevenue) {
                return; // immaterial
            }

            $sev    = $price > 0 ? $this->severityFromImpact($impact) : Anomaly::SEVERITY_MEDIUM;
            $devPct = (int) round(abs($dev) / $f * 100);
            $where  = $storeId !== null ? (' at ' . ($storeLabels[$storeId] ?? "store {$storeId}")) : ' chain-wide';
            $src    = $source ?: 'plan';

            $this->flag($tenantId, 'plan_variance', $sev, $sku, $storeId, null,
                "SKU {$sku}{$where} sold " . round($a) . " units over the last {$days} days vs a planned "
                . round($f) . " — {$devPct}% {$dir} the {$src} forecast.",
                [
                    'model'          => 'ingested_plan',
                    'plan_source'    => $src,
                    'forecast_units' => round($f, 1),
                    'actual_units'   => round($a, 1),
                    'deviation_pct'  => $devPct,
                    'direction'      => $dir,
                    'revenue_impact' => round($impact, 2),
                    'window_days'    => $days,
                    'matched_days'   => $matchedDays,
                ]
            );
        };

        foreach ($storeRows as $r) {
            $emit(trim((string) $r->sku), (int) $r->store_id, (float) $r->f, (float) $r->a, $r->source, (int) $r->matched_days);
        }
        foreach ($chainRows as $r) {
            $emit(trim((string) $r->sku), null, (float) $r->f, (float) $r->a, $r->source, (int) $r->matched_days);
        }
    }

    /**
     * Planned-order vs actual-receipts variance. The sibling of `plan_variance` on
     * the SUPPLY side: where plan_variance asks "did we SELL what the plan expected",
     * this asks "did we ORDER/RECEIVE what the plan called for". It pairs the plan's
     * `planned_order_qty` (plan_forecasts, ingested from RELEX / Blue Yonder /
     * Slimstock / ERP planning) against actual goods receipts (`purchase_orders.
     * qty_received`) over a window.
     *
     * Aggregated at CHAIN level per SKU on purpose: order/replenishment flows and
     * goods receipts are managed at DC/chain level and `purchase_orders.store_id`
     * is frequently null, so attributing receipts to a store would false-flag
     * "under-received". Summed planned orders vs summed receipts over the window;
     * flags a material, priced gap. "Planned to order, received nothing" is the
     * highest-value case (a missed replenishment → coming stockout). Only fires for
     * tenants who ingest planned orders (planned_order_qty present). No plan → no work.
     */
    private function detectOrderPlanVariance(int $tenantId, array $thresholds): void
    {
        $tol        = (float) ($thresholds['pct'] ?? 25) / 100;
        $days       = (int) ($thresholds['days'] ?? 30);   // order cycles are lumpier than daily demand
        $minUnits   = (float) ($thresholds['min_units'] ?? 1);
        $minValue   = (float) ($thresholds['min_value'] ?? self::DEFAULT_MIN_REVENUE);

        $to   = $this->analysisDate()->format('Y-m-d');
        $from = $this->analysisDate()->copy()->subDays($days - 1)->format('Y-m-d');

        [$scopePlan, $planBind] = $this->scopeSql('sku');
        [$scopePo,   $poBind]   = $this->scopeSql('sku');

        // Planned orders per SKU over the window (freshest source per sku/store/day,
        // then summed across stores + days). Only plan points that carry an order plan.
        $plannedRows = DB::select(
            "SELECT sku, SUM(planned) AS planned
             FROM (
                 SELECT DISTINCT ON (sku, store_id, target_date) sku, planned_order_qty AS planned
                 FROM plan_forecasts
                 WHERE tenant_id = ? AND planned_order_qty IS NOT NULL
                   AND target_date BETWEEN ? AND ?{$scopePlan}
                 ORDER BY sku, store_id, target_date, updated_at DESC
             ) p
             GROUP BY sku",
            array_merge([$tenantId, $from, $to], $planBind),
        );

        if (empty($plannedRows)) {
            return; // no ingested order plan for the window — nothing to measure
        }

        // Actual goods receipts per SKU over the same window.
        $recvRows = DB::select(
            "SELECT sku, SUM(qty_received) AS recv
             FROM purchase_orders
             WHERE tenant_id = ? AND received_date IS NOT NULL AND qty_received IS NOT NULL
               AND received_date BETWEEN ? AND ?{$scopePo}
             GROUP BY sku",
            array_merge([$tenantId, $from, $to], $poBind),
        );

        $received = [];
        foreach ($recvRows as $r) {
            $received[trim((string) $r->sku)] = (float) $r->recv;
        }

        foreach ($plannedRows as $r) {
            $sku     = trim((string) $r->sku);
            $planned = (float) $r->planned;
            if ($planned < $minUnits || $planned <= 0) {
                continue;
            }

            $recv = $received[$sku] ?? 0.0;
            $dir  = null;
            if ($recv < $planned * (1 - $tol)) {
                $dir = 'under';   // received less than planned → replenishment shortfall
            } elseif ($recv > $planned * (1 + $tol)) {
                $dir = 'over';    // received more than planned → over-ordering / cash tied up
            }
            if ($dir === null) {
                continue;
            }

            $cost   = $this->unitCost($sku);
            $delta  = abs($recv - $planned);
            $value  = $delta * $cost;
            if ($cost > 0 && $value < $minValue) {
                continue;
            }

            $sev    = $cost > 0 ? $this->severityFromImpact($value) : Anomaly::SEVERITY_MEDIUM;
            $devPct = (int) round($delta / $planned * 100);
            $verb   = $dir === 'under' ? 'below' : 'above';

            $this->flag($tenantId, 'order_plan_variance', $sev, $sku, null, null,
                "SKU {$sku} received " . round($recv) . " units over the last {$days} days vs a planned "
                . round($planned) . " to order — {$devPct}% {$verb} the plan"
                . ($cost > 0 ? ' (' . $this->money($value) . ' ' . ($dir === 'under' ? 'under-supplied' : 'over-supplied') . ')' : '') . '.',
                [
                    'model'          => 'ingested_plan',
                    'planned_units'  => round($planned, 1),
                    'received_units' => round($recv, 1),
                    'deviation_pct'  => $devPct,
                    'direction'      => $dir,
                    'value_impact'   => round($value, 2),
                    'window_days'    => $days,
                ]
            );
        }
    }

    // =========================================================================
    // INVENTORY & SUPPLY
    // =========================================================================

    /**
     * Demand-aware stockout risk. A stockout that matters is a SKU that *sells*
     * but whose current stock has hit (or fallen below) the line — a lost-sales
     * event. This does NOT require a reorder_point: if one exists we use it,
     * otherwise on-hand at/near zero while the SKU has real recent demand is the
     * signal. Impact = expected lost sales over the horizon × price.
     */
    private function detectStockoutRisk(int $tenantId, array $thresholds): void
    {
        $minUnits = (float)($thresholds['min_units'] ?? 3);
        // Business-impact floor (B1): suppress stockouts whose lost-sales value
        // over the horizon is below this, when we know the price. Defaults to a
        // gentle 100 so the trivial tail drops but staples stay; impact still
        // drives severity so high-velocity stockouts rank to the top. Only floors
        // when price is known (see the guard below), never on price-less items.
        $minValue = (float)($thresholds['min_revenue'] ?? 100);
        $horizon  = (int)($thresholds['lost_sales_days'] ?? 7);
        $days     = $this->demandWindowDays;

        // Reads the shared snapshot + demand maps (primed once per run). Only
        // SKUs that actually sell can suffer a lost-sales stockout, so we iterate
        // demand — the narrower set.
        foreach (($this->recentDemand ?? []) as $k => $units) {
            if ($units < $minUnits) continue;

            $oh = $this->latestOnHand[$k] ?? null;
            if ($oh === null) continue; // no stock snapshot for a selling SKU → can't assert a stockout here

            $reorder    = $oh['reorder'];
            $isStockout = $oh['qty'] <= 0 || ($reorder !== null && $reorder > 0 && $oh['qty'] <= $reorder);
            if (! $isStockout) continue;

            [$storeId, $sku] = explode('|', $k, 2);

            $dailyDemand = $units / max(1, $days);
            // v2 (audit M): only the demand the stock on hand can't cover is lost.
            $lostUnits   = $this->v2
                ? max(0.0, $dailyDemand * $horizon - max(0.0, $oh['qty']))
                : $dailyDemand * $horizon;
            if ($this->v2 && $lostUnits <= 0) continue;
            $price       = $this->unitPrice($sku);
            $impact      = $lostUnits * $price;
            if ($price > 0 && $impact < $minValue) continue;

            $severity = $price > 0 ? $this->severityFromImpact($impact) : Anomaly::SEVERITY_HIGH;

            $rpLabel = (($oh['reorder_source'] ?? null) === 'derived') ? 'derived reorder point' : 'reorder point';
            $line = ($reorder !== null && $reorder > 0)
                ? "on hand {$oh['qty']} ≤ {$rpLabel} " . round($reorder, 1)
                : "on hand {$oh['qty']}";

            // B4: prescriptive suggestion — how much to order and from whom.
            $rep       = $this->replenishment[$k] ?? null;
            $suggestQty = $rep && ($rep['suggested_order_qty'] ?? 0) > 0 ? (float) $rep['suggested_order_qty'] : null;
            $supplier   = $rep['supplier'] ?? null;
            $suggestClause = $suggestQty !== null
                ? " Suggest ordering ~" . round($suggestQty) . " units" . ($supplier ? " from {$supplier}" : "") . "."
                : "";

            $this->flag($tenantId, 'stockout_risk', $severity, $sku, (int) $storeId, $oh['product_id'],
                "SKU {$sku} is at stockout risk at '{$oh['location']}' — {$line}, while it sells ~"
                . round($dailyDemand, 1) . " units/day (" . $this->money($impact) . " lost sales at risk over {$horizon} days)." . $suggestClause,
                [
                    'on_hand_qty'    => $oh['qty'],
                    'reorder_point'  => $reorder,
                    'reorder_source' => $oh['reorder_source'] ?? 'tenant',
                    'daily_demand'   => round($dailyDemand, 2),
                    'lost_units'     => round($lostUnits, 1),
                    'revenue_impact' => round($impact, 2),
                    'suggested_order_qty' => $suggestQty !== null ? round($suggestQty, 1) : null,
                    'suggested_supplier'  => $supplier,
                    'location'       => $oh['location'],
                    'lookback_days'  => $days,
                ]
            );
        }
    }

    /**
     * Data-integrity rule: a (store, SKU) whose LATEST on-hand snapshot is below
     * zero. Negative stock is never physically real — it means a receipt was
     * missed, a sale was double-counted, or two feeds disagree. It silently
     * corrupts stockout, dead-stock, phantom and overstock math, so it is flagged
     * unconditionally (no money floor) at high severity. Precision is ~100% by
     * construction: the condition IS the error.
     */
    private function detectNegativeInventory(int $tenantId): void
    {
        // Reads the shared latest-snapshot map; flags any combo that ends negative.
        foreach (($this->latestOnHand ?? []) as $k => $oh) {
            if ($oh['qty'] >= 0) continue;

            [$storeId, $sku] = explode('|', $k, 2);
            $loc = $oh['location'] ? " at '{$oh['location']}'" : '';

            $this->flag($tenantId, 'negative_inventory', Anomaly::SEVERITY_HIGH, $sku, (int) $storeId, $oh['product_id'],
                "SKU {$sku}{$loc} shows a negative on-hand balance of " . round($oh['qty']) . " units "
                . "(as of {$oh['d']}) — a data-integrity error: a receipt was likely missed or a sale double-counted.",
                [
                    'on_hand_qty'   => $oh['qty'],
                    'location'      => $oh['location'],
                    'as_of_date'    => $oh['d'],
                ]
            );
        }
    }

    /**
     * Overstock by days-of-cover: a (store, SKU) whose latest on-hand would take
     * more than `days_cover` days to sell through at its recent demand rate. This
     * is the mirror image of stockout — capital frozen in stock that moves too
     * slowly to justify the quantity held. Distinct from dead_stock/phantom
     * (which require ~zero demand): overstock REQUIRES real ongoing demand, just
     * far too little relative to the pile. Ranked by tied-up value (on-hand ×
     * cost); a materiality floor keeps it to positions worth rebalancing.
     */
    private function detectOverstock(int $tenantId, array $thresholds): void
    {
        $daysCover = (float)($thresholds['days_cover'] ?? 120);
        $minValue  = (float)($thresholds['min_value'] ?? 1000);
        $lookback  = $this->demandWindowDays;

        // Reads the shared snapshot + demand maps (primed once per run).
        foreach (($this->latestOnHand ?? []) as $k => $oh) {
            if ($oh['qty'] <= 0) continue; // positive stock only

            $units = $this->recentDemand[$k] ?? 0.0;
            if ($units <= 0) continue; // no demand → dead stock, not overstock

            $dailyDemand = $units / max(1, $lookback);
            if ($dailyDemand <= 0) continue;

            $cover = $oh['qty'] / $dailyDemand; // days it would take to clear at this rate
            if ($cover <= $daysCover) continue;

            [$storeId, $sku] = explode('|', $k, 2);
            // v2: a store missing from the sales feed has unknown, not low, demand.
            if ($this->v2 && ! isset($this->storesWithSales[(int) $storeId])) continue;
            $cost  = $this->unitCost($sku);
            $value = $oh['qty'] * $cost;
            if ($cost > 0 && $value < $minValue) continue;

            $severity = $cost > 0 ? $this->severityFromImpact($value) : Anomaly::SEVERITY_LOW;

            $this->flag($tenantId, 'overstock', $severity, $sku, (int) $storeId, $oh['product_id'],
                "SKU {$sku} holds " . round($oh['qty']) . " units (" . $this->money($value) . ") at '{$oh['location']}' "
                . "— about " . round($cover) . " days of cover at its recent rate of " . round($dailyDemand, 1)
                . " units/day (threshold {$daysCover} days). Working capital tied up in slow-moving stock.",
                [
                    'on_hand_qty'     => $oh['qty'],
                    'daily_demand'    => round($dailyDemand, 2),
                    'days_of_cover'   => round($cover),
                    'threshold_days'  => $daysCover,
                    'inventory_value' => round($value, 2),
                    'revenue_impact'  => round($value, 2),
                    'location'        => $oh['location'],
                    'lookback_days'   => $lookback,
                ]
            );
        }
    }

    private function detectSafetyStockBreach(int $tenantId): void
    {
        // Reads the shared latest-snapshot map: a (store, sku) whose most-recent
        // on-hand sits below half its reorder point. Using the latest snapshot
        // (not every historical row) also stops stale rows from re-firing.
        foreach (($this->latestOnHand ?? []) as $k => $oh) {
            $reorder = $oh['reorder'];
            if ($reorder === null || $reorder <= 0) continue;
            if ($oh['qty'] >= $reorder * 0.5) continue;

            [$storeId, $sku] = explode('|', $k, 2);
            $safetyProxy = round($reorder * 0.5, 1);
            $loc = $oh['location'] ? " at {$oh['location']}" : '';
            $this->flag($tenantId, 'safety_stock_breach', 'high', $sku, (int) $storeId, $oh['product_id'],
                "SKU {$sku}{$loc} is critically low — on hand: {$oh['qty']} (below safety proxy of {$safetyProxy}, 50% of reorder point {$reorder}).",
                ['on_hand_qty' => $oh['qty'], 'reorder_point' => $reorder, 'safety_stock_proxy' => $safetyProxy, 'location' => $oh['location']]
            );
        }
    }

    private function detectDeadStock(int $tenantId, array $thresholds): void
    {
        $days      = (int)($thresholds['days'] ?? 30);
        $sinceDate = $this->analysisDate()->subDays($days)->format('Y-m-d');

        $inventoried = InventoryLevel::where('tenant_id', $tenantId)
            ->where('on_hand_qty', '>', 0)
            ->pluck('sku')
            ->unique();

        $activeSKUs = SalesTransaction::where('tenant_id', $tenantId)
            ->where('date', '>=', $sinceDate)
            ->pluck('sku')
            ->unique();

        foreach ($inventoried->diff($activeSKUs) as $sku) {
            $level = InventoryLevel::where('tenant_id', $tenantId)->where('sku', $sku)->first();
            $this->flag($tenantId, 'dead_stock', 'low', $sku, $level?->store_id, $level?->product_id,
                "SKU {$sku} has " . round((float)($level?->on_hand_qty ?? 0)) . " units in inventory but no sales in the last {$days} days.",
                ['on_hand_qty' => $level?->on_hand_qty, 'days_without_sales' => $days]
            );
        }
    }

    /**
     * Store-level phantom / non-moving inventory: a (store, SKU) that holds stock
     * but has negligible demand AT THAT STORE — capital tied up where it doesn't
     * sell (even if the SKU sells fine elsewhere). Ranked by tied-up value
     * (on-hand × cost). A materiality floor keeps it to inventory worth acting on;
     * lower `min_value` to widen recall (at the cost of a much longer list).
     */
    private function detectPhantomInventory(int $tenantId, array $thresholds): void
    {
        $maxDemand = (float)($thresholds['max_demand'] ?? 1);   // "negligible" = ≤ this many units in the window
        $minValue  = (float)($thresholds['min_value'] ?? self::DEFAULT_MIN_REVENUE);
        $days      = $this->demandWindowDays;

        // Reads the shared snapshot + demand maps (primed once per run).
        foreach (($this->latestOnHand ?? []) as $k => $oh) {
            if ($oh['qty'] <= 0) continue;
            $recent = $this->recentDemand[$k] ?? 0.0;
            if ($recent > $maxDemand) continue; // it sells here → not phantom

            [$storeId, $sku] = explode('|', $k, 2);
            // v2: a store missing from the sales feed has unknown, not zero, demand.
            if ($this->v2 && ! isset($this->storesWithSales[(int) $storeId])) continue;
            $cost  = $this->unitCost($sku);
            $value = $oh['qty'] * $cost;        // capital tied up in non-moving stock
            if ($cost > 0 && $value < $minValue) continue;

            $severity = $cost > 0 ? $this->severityFromImpact($value) : Anomaly::SEVERITY_MEDIUM;

            $this->flag($tenantId, 'phantom_inventory', $severity, $sku, (int) $storeId, $oh['product_id'],
                "SKU {$sku} holds " . round($oh['qty']) . " units (" . $this->money($value) . ") at '{$oh['location']}' "
                . "but sold only " . round($recent, 1) . " units there in {$days} days — capital tied up in non-moving stock.",
                [
                    'on_hand_qty'     => $oh['qty'],
                    'recent_demand'   => round($recent, 2),
                    'inventory_value' => round($value, 2),
                    'revenue_impact'  => round($value, 2),
                    'location'        => $oh['location'],
                    'lookback_days'   => $days,
                ]
            );
        }
    }

    private function detectMultiLocationImbalance(int $tenantId): void
    {
        // Stream rows and keep only a compact per-SKU summary, so a 200k-row
        // inventory table never all lives in memory at once.
        $acc = [];
        InventoryLevel::where('tenant_id', $tenantId)
            ->whereNotNull('location')
            ->select(['sku', 'store_id', 'location', 'on_hand_qty', 'reorder_point', 'product_id'])
            ->cursor()
            ->each(function ($l) use (&$acc) {
                $sku = $l->sku;
                $oh  = (float) $l->on_hand_qty;
                // B4: fall back to the derived reorder point where the tenant has none,
                // so the imbalance rule works even without supplied reorder points.
                $rp  = $l->reorder_point !== null
                    ? (float) $l->reorder_point
                    : ($this->replenishment[$l->store_id . '|' . trim((string) $sku)]['reorder_point'] ?? null);

                if (! isset($acc[$sku])) {
                    $acc[$sku] = ['count' => 0, 'out' => null, 'over' => null, 'product_id' => $l->product_id];
                }
                $acc[$sku]['count']++;

                $isOut  = $oh <= 0 || ($rp !== null && $oh <= $rp);
                $isOver = $rp !== null && $oh > $rp * 2;

                if ($isOut && $acc[$sku]['out'] === null) {
                    $acc[$sku]['out'] = ['loc' => $l->location, 'qty' => $oh];
                }
                if ($isOver && ($acc[$sku]['over'] === null || $oh > $acc[$sku]['over']['qty'])) {
                    $acc[$sku]['over'] = ['loc' => $l->location, 'qty' => $oh];
                }
            });

        foreach ($acc as $sku => $a) {
            if ($a['count'] < 2 || $a['out'] === null || $a['over'] === null) continue;

            $surplusQty = round($a['over']['qty']);
            $this->flag($tenantId, 'multi_location_imbalance', 'medium', $sku, null, $a['product_id'],
                "SKU {$sku} is stocked out at '{$a['out']['loc']}' while '{$a['over']['loc']}' has {$surplusQty} units — consider rebalancing.",
                [
                    'stocked_out_location'  => $a['out']['loc'],
                    'overstocked_location'  => $a['over']['loc'],
                    'surplus_qty'           => $surplusQty,
                    'stocked_out_qty'       => $a['out']['qty'],
                ]
            );
        }
    }

    private function detectReorderPointStaleness(int $tenantId, array $thresholds): void
    {
        $days        = (int)($thresholds['days'] ?? 90);
        $recentCut   = $this->analysisDate()->subDays(30)->format('Y-m-d');
        $staleBefore = $this->analysisDate()->subDays($days)->format('Y-m-d');

        // SKUs that actually sold in the last 30 days — only their reorder points
        // are worth questioning.
        $activeSkus = SalesTransaction::where('tenant_id', $tenantId)
            ->where('date', '>=', $recentCut)
            ->distinct()
            ->pluck('sku')
            ->flip();

        // Judge the LATEST snapshot per (store, sku) from the primed map. The
        // reorder point is stale only if the MOST RECENT snapshot carrying it
        // predates the staleness window — so a year of older history no longer
        // makes every SKU look stale (the previous query flagged any SKU that had
        // *any* snapshot older than the window, i.e. all of them). Uses the primed
        // snapshot instead of loading the whole inventory history into memory.
        foreach (($this->latestOnHand ?? []) as $k => $oh) {
            $rp   = $oh['reorder'] ?? null;
            $date = $oh['d'] ?? null;
            if (!$rp || $rp <= 0 || !$date || $date === '0000-00-00') continue;
            if ($date >= $staleBefore) continue; // latest snapshot is recent → reorder point is current

            [$storeId, $sku] = explode('|', $k, 2);
            if (!isset($activeSkus[$sku])) continue;

            $daysStale = Carbon::parse($date)->diffInDays($this->analysisDate());
            $this->flag($tenantId, 'reorder_point_staleness', 'low', $sku, (int) $storeId, $oh['product_id'],
                "SKU {$sku} has a reorder point of " . round($rp) . " that hasn't refreshed in {$daysStale} days — may not reflect current sales velocity.",
                ['reorder_point' => $rp, 'as_of_date' => $date, 'days_stale' => $daysStale, 'location' => $oh['location']]
            );
        }
    }

    /**
     * Unexplained inventory shrinkage between the two most recent snapshots of a
     * (sku, location): stock fell by more than sales can account for.
     *
     * The original ran a fetch-snapshots + sum-sales query PER pair — ~200k
     * round-trips, ~684s, essentially the entire detection run. This does the
     * whole rule in ONE query: a LEAD() window pairs each latest snapshot with its
     * previous one (backed by idx_inv_pair_snapshot, so no full sort), and a
     * LATERAL join sums each dropped pair's sales over ITS OWN date window (backed
     * by idx_sales_daily_sku_date). The shrinkage threshold is applied in SQL, so
     * only the handful of genuine hits return to PHP — no per-window loop (the
     * data has thousands of distinct snapshot windows) and no large buffer.
     */
    private function detectInventoryShrinkage(int $tenantId, array $thresholds): void
    {
        $pct      = (float)($thresholds['pct'] ?? 20);
        $minValue = (float)($thresholds['min_value'] ?? self::DEFAULT_MIN_REVENUE);

        [$scopeSql, $scopeBind] = $this->scopeSql('sku');

        $rows = DB::select(
            "WITH drops AS (
                SELECT store_id, sku, location, product_id, latest_qty, prev_qty, prev_date, latest_date
                FROM (
                    SELECT store_id, sku, location, product_id,
                           on_hand_qty AS latest_qty,
                           LEAD(on_hand_qty) OVER w AS prev_qty,
                           as_of_date       AS latest_date,
                           LEAD(as_of_date) OVER w AS prev_date,
                           ROW_NUMBER()     OVER w AS rn
                    FROM inventory_levels
                    WHERE tenant_id = ? AND as_of_date IS NOT NULL AND location IS NOT NULL{$scopeSql}
                    WINDOW w AS (PARTITION BY sku, location ORDER BY as_of_date DESC)
                ) t
                WHERE rn = 1 AND prev_qty IS NOT NULL AND prev_qty > 0 AND latest_qty < prev_qty
            )
            SELECT d.store_id, d.sku, d.location, d.product_id, d.latest_qty, d.prev_qty,
                   TO_CHAR(d.prev_date,   'YYYY-MM-DD') AS prev_date,
                   TO_CHAR(d.latest_date, 'YYYY-MM-DD') AS latest_date,
                   COALESCE(sd.q, 0) AS sales,
                   (d.prev_qty - COALESCE(sd.q, 0) - d.latest_qty) AS unexplained
            FROM drops d
            LEFT JOIN LATERAL (
                SELECT SUM(s.units_sold) AS q
                FROM sales_daily s
                WHERE s.tenant_id = ? AND s.sku = d.sku
                  AND s.date >= d.prev_date::date AND s.date <= d.latest_date::date
            ) sd ON TRUE
            WHERE (d.prev_qty - COALESCE(sd.q, 0) - d.latest_qty) > 0
              AND ((d.prev_qty - COALESCE(sd.q, 0) - d.latest_qty) / d.prev_qty) * 100 >= ?",
            array_merge([$tenantId], $scopeBind, [$tenantId, $pct])
        );

        foreach ($rows as $row) {
            $prevQty      = (float) $row->prev_qty;
            $latestQty    = (float) $row->latest_qty;
            $sales        = (float) $row->sales;
            $unexplained  = (float) $row->unexplained;
            $shrinkagePct = ($unexplained / $prevQty) * 100;

            // Gate on the cost of the missing units so a 20% shrink of a penny
            // item doesn't rank alongside a real loss. Only floors when cost is known.
            $cost  = $this->unitCost($row->sku);
            $value = $unexplained * $cost;
            if ($cost > 0 && $value < $minValue) continue;
            $severity = $cost > 0 ? $this->severityFromImpact($value) : Anomaly::SEVERITY_HIGH;

            $this->flag($tenantId, 'inventory_shrinkage', $severity, $row->sku, $row->store_id, $row->product_id,
                "SKU {$row->sku} at '{$row->location}' shows " . round($shrinkagePct) . "% inventory shrinkage — "
                . round($unexplained) . " units (" . $this->money($value) . ") unaccounted for between "
                . $row->prev_date . " and " . $row->latest_date . ".",
                [
                    'location'        => $row->location,
                    'prev_qty'        => $prevQty,
                    'latest_qty'      => $latestQty,
                    'sales_in_period' => $sales,
                    'unexplained'     => round($unexplained, 2),
                    'shrinkage_pct'   => round($shrinkagePct, 1),
                    'revenue_impact'  => round($value, 2),
                ]
            );
        }
    }

    /**
     * Concealed shrink — unexplained inventory loss accumulated across the FULL
     * snapshot history of a (SKU, location), not just the latest two snapshots.
     *
     * `inventory_shrinkage` compares only the two most recent snapshots, so a
     * sawtooth (lose stock → restock → lose again) hides: the last pair looks
     * fine. This sums the unexplained loss (prev − sales − curr, when positive)
     * over EVERY declining interval in the series. Only declining intervals can
     * contribute, so we filter to those before the per-interval sales lookup
     * (backed by idx_sales_daily_sku_date); result is one row per genuinely-
     * leaking (SKU, location).
     */
    private function detectCumulativeShrink(int $tenantId, array $thresholds): void
    {
        $minValue     = (float)($thresholds['min_value'] ?? 1000);
        $minIntervals = (int)($thresholds['min_intervals'] ?? 3);

        [$scopeSql, $scopeBind] = $this->scopeSql('sku');

        $rows = DB::select(
            "WITH ivals AS (
                SELECT store_id, sku, location, product_id,
                       on_hand_qty            AS curr_qty,
                       LAG(on_hand_qty) OVER w AS prev_qty,
                       LAG(as_of_date)  OVER w AS prev_date,
                       as_of_date              AS curr_date
                FROM inventory_levels
                WHERE tenant_id = ? AND as_of_date IS NOT NULL AND location IS NOT NULL{$scopeSql}
                WINDOW w AS (PARTITION BY sku, location ORDER BY as_of_date ASC)
            ),
            drops AS (
                SELECT * FROM ivals WHERE prev_qty IS NOT NULL AND prev_qty > curr_qty
            )
            SELECT d.store_id, d.sku, d.location, MAX(d.product_id) AS product_id,
                   SUM(GREATEST(0, d.prev_qty - COALESCE(s.sales, 0) - d.curr_qty)) AS cum_unexplained,
                   COUNT(*) FILTER (WHERE (d.prev_qty - COALESCE(s.sales, 0) - d.curr_qty) > 0) AS loss_intervals
            FROM drops d
            LEFT JOIN LATERAL (
                SELECT SUM(sd.units_sold) AS sales
                FROM sales_daily sd
                WHERE sd.tenant_id = ? AND sd.sku = d.sku
                  AND sd.date > d.prev_date::date AND sd.date <= d.curr_date::date
            ) s ON TRUE
            GROUP BY d.store_id, d.sku, d.location
            HAVING SUM(GREATEST(0, d.prev_qty - COALESCE(s.sales, 0) - d.curr_qty)) > 0
               AND COUNT(*) FILTER (WHERE (d.prev_qty - COALESCE(s.sales, 0) - d.curr_qty) > 0) >= ?",
            array_merge([$tenantId], $scopeBind, [$tenantId, $minIntervals])
        );

        foreach ($rows as $r) {
            $cum = (float) $r->cum_unexplained;
            if ($cum <= 0) continue;

            $cost  = $this->unitCost($r->sku);
            $value = $cum * $cost;
            if ($cost > 0 && $value < $minValue) continue;
            $severity = $cost > 0 ? $this->severityFromImpact($value) : Anomaly::SEVERITY_HIGH;

            $this->flag($tenantId, 'cumulative_shrink', $severity, $r->sku, (int) $r->store_id, $r->product_id,
                "SKU {$r->sku} at '{$r->location}' shows a concealed shrink pattern — "
                . round($cum) . " units (" . $this->money($value) . ") of unexplained loss accumulated across "
                . (int) $r->loss_intervals . " separate declines, masked by restocking in between.",
                [
                    'location'         => $r->location,
                    'cumulative_units' => round($cum, 1),
                    'loss_intervals'   => (int) $r->loss_intervals,
                    'inventory_value'  => round($value, 2),
                    'revenue_impact'   => round($value, 2),
                ]
            );
        }
    }

    // =========================================================================
    // PURCHASE ORDERS
    // =========================================================================

    private function detectPoOverdue(int $tenantId): void
    {
        $today = $this->analysisDate()->format('Y-m-d');

        $pos = PurchaseOrder::where('tenant_id', $tenantId)
            ->whereNotNull('expected_date')
            ->where('expected_date', '<', $today)
            ->whereNull('received_date')
            // WP1.4 (audit H19): a PO with nothing received has qty_received
            // NULL — the most common overdue case — and NULL < n is not true.
            ->whereRaw('COALESCE(qty_received, 0) < qty_ordered')
            ->get();

        foreach ($pos as $po) {
            $daysOverdue = (int) Carbon::parse($po->expected_date)->diffInDays($this->analysisDate());
            $this->flag($tenantId, 'po_overdue', 'medium', $po->sku, null, $po->product_id,
                "PO #{$po->po_number} from {$po->supplier} (SKU {$po->sku}) is {$daysOverdue} day(s) overdue "
                . "(expected: {$po->expected_date}, received: {$po->qty_received}/{$po->qty_ordered}).",
                ['po_number' => $po->po_number, 'supplier' => $po->supplier, 'days_overdue' => $daysOverdue, 'qty_ordered' => $po->qty_ordered, 'qty_received' => $po->qty_received]
            );
        }
    }

    private function detectReceivingDiscrepancy(int $tenantId, array $thresholds): void
    {
        $pct       = (float)($thresholds['pct'] ?? 20);
        $threshold = 1 - ($pct / 100);
        $minValue  = (float)($thresholds['min_value'] ?? self::DEFAULT_MIN_REVENUE);

        // Push the shortfall filter into SQL so only discrepant POs load, and
        // stream them. Gate on the cost of the shortfall so trivial under-receipts
        // don't flood the list.
        PurchaseOrder::where('tenant_id', $tenantId)
            ->whereNotNull('received_date')
            ->where('qty_ordered', '>', 0)
            ->whereRaw('qty_received < qty_ordered * ?', [$threshold])
            ->select(['po_number', 'supplier', 'sku', 'qty_ordered', 'qty_received', 'product_id'])
            ->cursor()
            ->each(function ($po) use ($tenantId, $minValue) {
                $ordered   = (float) $po->qty_ordered;
                $received  = (float) $po->qty_received;
                $shortfall = max(0.0, $ordered - $received);

                $cost  = $this->unitCost($po->sku);
                $value = $shortfall * $cost;
                if ($cost > 0 && $value < $minValue) return;
                $severity = $cost > 0 ? $this->severityFromImpact($value) : Anomaly::SEVERITY_MEDIUM;

                $receivedPct = round(($received / $ordered) * 100);
                $this->flag($tenantId, 'receiving_discrepancy', $severity, $po->sku, null, $po->product_id,
                    "PO #{$po->po_number} from {$po->supplier} (SKU {$po->sku}) was closed with only {$receivedPct}% received "
                    . "({$po->qty_received} of {$po->qty_ordered} units, " . $this->money($value) . " short).",
                    ['po_number' => $po->po_number, 'supplier' => $po->supplier, 'qty_ordered' => $po->qty_ordered, 'qty_received' => $po->qty_received, 'received_pct' => $receivedPct, 'revenue_impact' => round($value, 2)]
                );
            });
    }

    /**
     * Late-but-received POs: a purchase order that DID arrive, but materially
     * later than its expected date. po_overdue only sees orders still outstanding
     * (received_date null); once goods finally land, that rule goes silent even
     * though the supplier missed the date — a service-level failure that never
     * surfaced. This closes that blind spot. Flagged when the receipt lag exceeds
     * `days` late; severity rises with lateness and, when priced, with the value
     * of the delayed goods.
     */
    private function detectPoLateReceipt(int $tenantId, array $thresholds): void
    {
        $minLate = (int)($thresholds['days'] ?? 7);

        PurchaseOrder::where('tenant_id', $tenantId)
            ->whereNotNull('expected_date')
            ->whereNotNull('received_date')
            ->whereColumn('received_date', '>', 'expected_date')
            ->select(['po_number', 'supplier', 'sku', 'qty_ordered', 'qty_received', 'expected_date', 'received_date', 'product_id'])
            ->cursor()
            ->each(function ($po) use ($tenantId, $minLate) {
                $daysLate = (int) Carbon::parse($po->expected_date)->diffInDays(Carbon::parse($po->received_date));
                if ($daysLate < $minLate) return;

                $received = (float) $po->qty_received;
                $value    = $received * $this->unitCost($po->sku);

                // Severity by lateness first; escalate on the value of the delayed goods.
                $severity = $daysLate >= 30 ? Anomaly::SEVERITY_HIGH
                    : ($daysLate >= 14 ? Anomaly::SEVERITY_MEDIUM : Anomaly::SEVERITY_LOW);
                if ($value >= 10000 && $severity === Anomaly::SEVERITY_LOW) {
                    $severity = Anomaly::SEVERITY_MEDIUM;
                }

                $this->flag($tenantId, 'po_late_receipt', $severity, $po->sku, null, $po->product_id,
                    "PO #{$po->po_number} from {$po->supplier} (SKU {$po->sku}) arrived {$daysLate} day(s) late "
                    . "(expected " . Carbon::parse($po->expected_date)->format('Y-m-d')
                    . ", received " . Carbon::parse($po->received_date)->format('Y-m-d') . ").",
                    [
                        'po_number'      => $po->po_number,
                        'supplier'       => $po->supplier,
                        'days_late'      => $daysLate,
                        'expected_date'  => Carbon::parse($po->expected_date)->format('Y-m-d'),
                        'received_date'  => Carbon::parse($po->received_date)->format('Y-m-d'),
                        'qty_received'   => $received,
                        'goods_value'    => round($value, 2),
                    ]
                );
            });
    }

    /**
     * Chronic supplier under-fill: a supplier that is persistently short on a SKU
     * across many POs. `receiving_discrepancy` tests each PO against a dollar
     * floor in isolation, so a supplier reliably 20–30% short on small orders
     * never surfaces — the pattern only shows in aggregate. Flags when the average
     * fill across at least `min_pos` POs is at or below `pct`%, ranked by the total
     * shortfall value.
     */
    private function detectSupplierFillRate(int $tenantId, array $thresholds): void
    {
        $pct      = (float)($thresholds['pct'] ?? 85);   // flag when avg fill <= this %
        $minPos   = (int)($thresholds['min_pos'] ?? 3);
        $minValue = (float)($thresholds['min_value'] ?? 200);
        $fillFrac = $pct / 100;

        $rows = DB::select(
            "SELECT supplier, sku, MAX(product_id) AS product_id,
                    COUNT(*) AS pos,
                    AVG(qty_received / NULLIF(qty_ordered, 0)) AS avg_fill,
                    SUM(qty_ordered - qty_received) AS short_units
             FROM purchase_orders
             WHERE tenant_id = ? AND qty_ordered > 0 AND qty_received IS NOT NULL AND supplier IS NOT NULL
             GROUP BY supplier, sku
             HAVING COUNT(*) >= ? AND AVG(qty_received / NULLIF(qty_ordered, 0)) <= ?",
            [$tenantId, $minPos, $fillFrac]
        );

        foreach ($rows as $r) {
            $shortUnits = max(0.0, (float) $r->short_units);
            $cost  = $this->unitCost($r->sku);
            $value = $shortUnits * $cost;
            if ($cost > 0 && $value < $minValue) continue;

            $avgFillPct = round((float) $r->avg_fill * 100);
            $severity = $cost > 0 ? $this->severityFromImpact($value) : Anomaly::SEVERITY_MEDIUM;

            $this->flag($tenantId, 'supplier_fill_rate', $severity, $r->sku, null, $r->product_id,
                "Supplier '{$r->supplier}' has chronically under-filled SKU {$r->sku} — averaging {$avgFillPct}% fill across "
                . (int) $r->pos . " POs (" . round($shortUnits) . " units short, " . $this->money($value) . ").",
                [
                    'supplier'       => $r->supplier,
                    'pos'            => (int) $r->pos,
                    'avg_fill_pct'   => $avgFillPct,
                    'short_units'    => round($shortUnits, 1),
                    'revenue_impact' => round($value, 2),
                ]
            );
        }
    }

    private function detectSupplierLeadTimeDrift(int $tenantId, array $thresholds): void
    {
        $pct       = (float)($thresholds['pct'] ?? 30);
        $recentCut = $this->analysisDate()->subDays(90)->format('Y-m-d');
        $histCut   = $this->analysisDate()->subDays(180)->format('Y-m-d');

        $recentPos = PurchaseOrder::where('tenant_id', $tenantId)
            ->whereNotNull('received_date')
            ->whereNotNull('order_date')
            ->where('order_date', '>=', $recentCut)
            ->get();

        $priorPos = PurchaseOrder::where('tenant_id', $tenantId)
            ->whereNotNull('received_date')
            ->whereNotNull('order_date')
            ->whereBetween('order_date', [$histCut, $recentCut])
            ->get();

        if ($recentPos->isEmpty() || $priorPos->isEmpty()) return;

        $leadDays = fn ($po) => Carbon::parse($po->order_date)->diffInDays(Carbon::parse($po->received_date));

        $recentBySupplier = $recentPos->groupBy('supplier')->map(fn ($pos) => $pos->avg($leadDays));
        $priorBySupplier  = $priorPos->groupBy('supplier')->map(fn ($pos) => $pos->avg($leadDays));

        foreach ($recentBySupplier as $supplier => $recentAvg) {
            $priorAvg = (float)($priorBySupplier->get($supplier) ?? 0);
            if ($priorAvg <= 0) continue;

            $driftPct = (($recentAvg - $priorAvg) / $priorAvg) * 100;

            if ($driftPct >= $pct) {
                $this->flag($tenantId, 'supplier_lead_time_drift', 'medium', null, null, null,
                    "Supplier '{$supplier}' average lead time grew " . round($driftPct) . "% "
                    . "(now " . round($recentAvg, 1) . " days vs prior " . round($priorAvg, 1) . " days).",
                    ['supplier' => $supplier, 'recent_avg_days' => round($recentAvg, 1), 'prior_avg_days' => round($priorAvg, 1), 'drift_pct' => round($driftPct, 1)]
                );
            }
        }
    }

    /**
     * Cost spike: a PO's unit cost is materially above baseline. Baseline is the
     * SKU's own prior-PO average when there are ≥2 priced POs; otherwise it falls
     * back to the product's STANDARD cost (`products.unit_cost`), so a single PO
     * priced well above the item's standard also surfaces (a supplier overcharge
     * on the first order, not only a rise over history).
     *
     * NOTE: requires `purchase_orders.unit_cost` in the feed. The importer maps it
     * (buildPurchaseOrderAttrs), but if the PO source has no cost column this rule
     * is correctly inert — a data-availability limit, not a logic gap.
     */
    private function detectCostSpike(int $tenantId, array $thresholds): void
    {
        $pct = (float)($thresholds['pct'] ?? 25);

        $allPos = PurchaseOrder::where('tenant_id', $tenantId)
            ->whereNotNull('unit_cost')
            ->where('unit_cost', '>', 0)
            ->orderByDesc('order_date')
            ->get()
            ->groupBy('sku');

        foreach ($allPos as $sku => $pos) {
            $latest     = $pos->first();
            $latestCost = (float) $latest->unit_cost;

            if ($pos->count() >= 2) {
                $baseline    = (float) $pos->slice(1)->avg('unit_cost');
                $baselineKind = 'historical avg';
            } else {
                $baseline    = $this->unitCost($sku);   // product standard cost
                $baselineKind = 'standard cost';
            }
            if ($baseline <= 0) continue;

            $spikePct = (($latestCost - $baseline) / $baseline) * 100;
            if ($spikePct < $pct) continue;

            $product = Product::where('tenant_id', $tenantId)->where('sku', $sku)->first();
            $this->flag($tenantId, 'cost_spike', 'high', $sku, null, $product?->id,
                "PO #{$latest->po_number} from {$latest->supplier} shows SKU {$sku} unit cost at " . $this->money($latestCost)
                . " — " . round($spikePct) . "% above {$baselineKind} of " . $this->money($baseline) . ".",
                ['supplier' => $latest->supplier, 'po_number' => $latest->po_number, 'latest_cost' => $latestCost,
                 'baseline' => round($baseline, 2), 'baseline_kind' => $baselineKind, 'spike_pct' => round($spikePct, 1)]
            );
        }
    }

    // =========================================================================
    // FINANCIAL
    // =========================================================================

    private function detectPriceAnomaly(int $tenantId, array $thresholds): void
    {
        $pct        = (float)($thresholds['pct'] ?? 25);
        $recentDate = $this->analysisDate()->subDays(30)->format('Y-m-d');

        $avgPrices = SalesTransaction::where('tenant_id', $tenantId)
            ->whereNotNull('unit_price')
            ->where('unit_price', '>', 0)
            ->selectRaw('sku, AVG(unit_price) as avg_price, COUNT(*) as cnt')
            ->groupBy('sku')
            ->havingRaw('COUNT(*) >= 3')   // PostgreSQL does not support alias references in HAVING
            ->pluck('avg_price', 'sku')
            ->map(fn ($v) => (float) $v);

        // Stream recent transactions, keeping only a compact per-SKU summary
        // (worst deviating price + anomaly count) so we never hold them all.
        $state         = [];
        $baselineCache = [];

        SalesTransaction::where('tenant_id', $tenantId)
            ->where('date', '>=', $recentDate)
            ->whereNotNull('unit_price')
            ->where('unit_price', '>', 0)
            ->select(['sku', 'unit_price', 'store_id', 'product_id'])
            ->cursor()
            ->each(function ($tx) use (&$state, &$baselineCache, $avgPrices, $tenantId, $pct) {
                $sku = $tx->sku;
                $avg = $avgPrices->get($sku);
                if (! $avg) return;

                if (! array_key_exists($sku, $baselineCache)) {
                    $baselineCache[$sku] = $this->baselines->getBaseline($tenantId, $sku, 'price_anomaly', 'unit_price');
                }
                $baseline = $baselineCache[$sku];
                $price    = (float) $tx->unit_price;

                if (! isset($state[$sku])) {
                    $state[$sku] = ['count' => 0, 'worst' => null];
                }

                if ($baseline) {
                    $z = abs($this->baselines->zScore($price, $baseline));
                    if ($z > $baseline->sensitivity_multiplier) {
                        $state[$sku]['count']++;
                        if ($state[$sku]['worst'] === null || $z > $state[$sku]['worst']['z']) {
                            $state[$sku]['worst'] = ['price' => $price, 'store_id' => $tx->store_id,
                                'product_id' => $tx->product_id, 'z' => $z,
                                'dev' => abs(($price - $avg) / max(0.001, $avg)) * 100];
                        }
                    }
                } else {
                    $dev = abs(($price - $avg) / $avg) * 100;
                    if ($dev >= $pct) {
                        $state[$sku]['count']++;
                        if ($state[$sku]['worst'] === null || $dev > $state[$sku]['worst']['dev']) {
                            $state[$sku]['worst'] = ['price' => $price, 'store_id' => $tx->store_id,
                                'product_id' => $tx->product_id, 'z' => 0.0, 'dev' => $dev];
                        }
                    }
                }
            });

        foreach ($state as $sku => $s) {
            if ($s['worst'] === null) continue;

            $avg      = (float) $avgPrices->get($sku);
            $baseline = $baselineCache[$sku] ?? null;
            $w        = $s['worst'];
            $direction = $w['price'] > $avg ? 'above' : 'below';

            if ($baseline) {
                $this->flag($tenantId, 'price_anomaly', 'low', $sku, $w['store_id'], $w['product_id'],
                    "SKU {$sku} has {$s['count']} recent price anomaly(ies) — worst: "
                    . $this->money($w['price']) . " (" . round($w['z'], 1) . "σ "
                    . $direction . " baseline mean " . $this->money($baseline->baseline_mean) . ").",
                    ['baseline_mean' => round($baseline->baseline_mean, 4), 'worst_price' => $w['price'],
                     'z_score' => round($w['z'], 2), 'count' => $s['count'], 'sensitivity' => $baseline->sensitivity_multiplier]
                );
            } else {
                $this->flag($tenantId, 'price_anomaly', 'low', $sku, $w['store_id'], $w['product_id'],
                    "SKU {$sku} has {$s['count']} recent transaction(s) with price anomalies — worst: "
                    . $this->money($w['price']) . " is " . round($w['dev']) . "% {$direction} the avg of " . $this->money($avg) . ".",
                    ['avg_price' => round($avg, 4), 'worst_price' => $w['price'], 'deviation_pct' => round($w['dev'], 1), 'count' => $s['count']]
                );
            }
        }
    }

    private function detectMarginErosion(int $tenantId): void
    {
        $recentDate = $this->analysisDate()->subDays(30)->format('Y-m-d');

        $products = Product::where('tenant_id', $tenantId)
            ->whereNotNull('unit_cost')
            ->where('unit_cost', '>', 0)
            ->pluck('unit_cost', 'sku')
            ->map(fn ($v) => (float) $v);

        if ($products->isEmpty()) return;

        // Stream recent transactions; keep only per-SKU below-cost count + worst.
        $state = [];
        SalesTransaction::where('tenant_id', $tenantId)
            ->where('date', '>=', $recentDate)
            ->whereNotNull('unit_price')
            ->where('unit_price', '>', 0)
            ->whereIn('sku', $products->keys())
            ->select(['sku', 'unit_price', 'store_id', 'product_id'])
            ->cursor()
            ->each(function ($tx) use (&$state, $products) {
                $sku  = $tx->sku;
                $cost = $products->get($sku);
                if ($cost === null) return;

                $price = (float) $tx->unit_price;
                if ($price >= $cost) return;

                if (! isset($state[$sku])) {
                    $state[$sku] = ['count' => 0, 'worst' => null];
                }
                $state[$sku]['count']++;
                if ($state[$sku]['worst'] === null || $price < $state[$sku]['worst']['price']) {
                    $state[$sku]['worst'] = ['price' => $price, 'store_id' => $tx->store_id, 'product_id' => $tx->product_id];
                }
            });

        foreach ($state as $sku => $s) {
            $cost = $products->get($sku);
            $w    = $s['worst'];
            $this->flag($tenantId, 'margin_erosion', 'high', $sku, $w['store_id'], $w['product_id'],
                "SKU {$sku} has {$s['count']} recent transaction(s) sold below cost — "
                . "worst: sold at " . $this->money($w['price']) . " vs unit cost of " . $this->money($cost) . ".",
                ['unit_cost' => $cost, 'worst_sale_price' => $w['price'], 'count' => $s['count']]
            );
        }
    }

    private function detectDiscountSignal(int $tenantId): void
    {
        $products = Product::where('tenant_id', $tenantId)
            ->whereNotNull('unit_cost')
            ->whereNotNull('selling_price')
            ->where('unit_cost', '>', 0)
            ->where('selling_price', '>', 0)
            ->get()
            ->filter(fn ($p) => (float) $p->selling_price < (float) $p->unit_cost);

        foreach ($products as $product) {
            $lossPct = abs(((float) $product->selling_price - (float) $product->unit_cost) / (float) $product->unit_cost) * 100;
            $this->flag($tenantId, 'discount_signal', 'medium', $product->sku, null, $product->id,
                "SKU {$product->sku} list price " . $this->money((float) $product->selling_price)
                . " is below unit cost " . $this->money((float) $product->unit_cost)
                . " — a built-in loss of " . round($lossPct, 1) . "% per unit sold.",
                ['selling_price' => $product->selling_price, 'unit_cost' => $product->unit_cost, 'loss_pct' => round($lossPct, 1)]
            );
        }
    }

    private function detectRevenueConcentrationRisk(int $tenantId, array $thresholds): void
    {
        $pct   = (float)($thresholds['pct'] ?? 80);
        $days  = (int)($thresholds['days'] ?? 90);
        $since = $this->analysisDate()->subDays($days)->format('Y-m-d');

        $revenue = SalesTransaction::where('tenant_id', $tenantId)
            ->where('date', '>=', $since)
            ->whereNotNull('total_amount')
            ->where('total_amount', '>', 0)
            ->selectRaw('sku, SUM(total_amount) as revenue')
            ->groupBy('sku')
            ->orderByDesc('revenue')
            ->get();

        if ($revenue->isEmpty()) return;

        $total    = (float) $revenue->sum('revenue');
        if ($total <= 0) return;

        $top      = $revenue->take(3);
        $topTotal = (float) $top->sum('revenue');
        $topPct   = ($topTotal / $total) * 100;

        if ($topPct >= $pct) {
            $topSkus = $top->pluck('sku')->implode(', ');
            $this->flag($tenantId, 'revenue_concentration_risk', 'medium', null, null, null,
                "Top 3 SKUs ({$topSkus}) account for " . round($topPct, 1) . "% of revenue in the last {$days} days — high concentration risk.",
                ['top_skus' => $top->pluck('sku')->toArray(), 'concentration_pct' => round($topPct, 1), 'top_revenue' => round($topTotal, 2), 'total_revenue' => round($total, 2), 'days' => $days]
            );
        }
    }

    private function detectSlowMovingCapital(int $tenantId, array $thresholds): void
    {
        $days     = (int)($thresholds['days'] ?? 60);
        $minValue = (float)($thresholds['min_value'] ?? 1000);
        $since    = $this->analysisDate()->subDays($days)->format('Y-m-d');

        $products = Product::where('tenant_id', $tenantId)
            ->whereNotNull('unit_cost')
            ->where('unit_cost', '>', 0)
            ->pluck('unit_cost', 'sku')
            ->map(fn ($v) => (float) $v);

        if ($products->isEmpty()) return;

        // Aggregate on-hand per SKU in SQL (one light row per SKU) instead of
        // loading every inventory row into memory.
        $inventory = InventoryLevel::where('tenant_id', $tenantId)
            ->where('on_hand_qty', '>', 0)
            ->whereIn('sku', $products->keys())
            ->selectRaw('sku, SUM(on_hand_qty) as total_qty, MAX(product_id) as product_id')
            ->groupBy('sku')
            ->get();

        $activeSkus = SalesTransaction::where('tenant_id', $tenantId)
            ->where('date', '>=', $since)
            ->distinct()
            ->pluck('sku');

        $activeSet = array_flip($activeSkus->all());

        foreach ($inventory as $row) {
            $sku = $row->sku;
            if (isset($activeSet[$sku])) continue;

            $totalQty   = (float) $row->total_qty;
            $unitCost   = $products->get($sku);
            $totalValue = $totalQty * $unitCost;

            if ($totalValue < $minValue) continue;

            $this->flag($tenantId, 'slow_moving_capital', 'medium', $sku, null, $row->product_id,
                "SKU {$sku} has " . $this->money($totalValue) . " tied up in inventory "
                . "(" . round($totalQty) . " units × " . $this->money($unitCost) . ") with no sales in the last {$days} days.",
                ['on_hand_qty' => $totalQty, 'unit_cost' => $unitCost, 'inventory_value' => round($totalValue, 2), 'days_without_sales' => $days]
            );
        }
    }

    // =========================================================================
    // STORE PERFORMANCE
    // =========================================================================

    private function detectStoreOutlier(int $tenantId, array $thresholds): void
    {
        $pct      = (float)($thresholds['pct'] ?? 50);
        $days     = (int)($thresholds['days'] ?? 7);
        $minValue = (float)($thresholds['min_value'] ?? self::DEFAULT_MIN_REVENUE);
        $since    = $this->analysisDate()->subDays($days)->format('Y-m-d');

        // The SUM/GROUP BY already collapses to one row per (sku, location);
        // stream it and keep a compact per-SKU list so nothing large is held.
        $bySku = [];
        SalesTransaction::where('tenant_id', $tenantId)
            ->where('date', '>=', $since)
            ->whereNotNull('location')
            ->selectRaw('sku, location, SUM(quantity) as qty')
            ->groupBy('sku', 'location')
            ->cursor()
            ->each(function ($r) use (&$bySku) {
                $bySku[$r->sku][] = ['location' => $r->location, 'qty' => (float) $r->qty];
            });

        foreach ($bySku as $sku => $rows) {
            if (count($rows) < 2) continue;

            $qtys = array_map(fn ($x) => $x['qty'], $rows);
            $avg  = array_sum($qtys) / count($qtys);
            if ($avg <= 0) continue;

            $price    = $this->unitPrice($sku);
            $baseline = $this->baselines->getBaseline($tenantId, $sku, 'store_outlier', 'location_qty');

            foreach ($rows as $row) {
                $qty = (float) $row['qty'];

                if ($baseline) {
                    // z = (mean - value) / stddev — large positive z means far below expected
                    $z = ($baseline->baseline_mean - $qty) / max(0.001, $baseline->baseline_stddev);
                    if ($z <= $baseline->sensitivity_multiplier) continue;

                    $value = max(0.0, $baseline->baseline_mean - $qty) * $price;
                    if ($price > 0 && $value < $minValue) continue;
                    $severity = $price > 0 ? $this->severityFromImpact($value) : Anomaly::SEVERITY_MEDIUM;

                    $this->flag($tenantId, 'store_outlier', $severity, $sku, null, null,
                        "SKU {$sku} at '{$row['location']}' sold " . round($qty) . " units over the period — "
                        . round($z, 1) . "σ below the baseline mean of " . round($baseline->baseline_mean, 1) . " units.",
                        ['location' => $row['location'], 'location_qty' => $qty,
                         'baseline_mean' => round($baseline->baseline_mean, 1),
                         'z_score' => round($z, 2), 'sensitivity' => $baseline->sensitivity_multiplier, 'days' => $days,
                         'revenue_impact' => round($value, 2)]
                    );
                } else {
                    $dropPct = (($avg - $qty) / $avg) * 100;
                    if ($dropPct < $pct) continue;

                    $value = max(0.0, $avg - $qty) * $price;
                    if ($price > 0 && $value < $minValue) continue;
                    $severity = $price > 0 ? $this->severityFromImpact($value) : Anomaly::SEVERITY_MEDIUM;

                    $this->flag($tenantId, 'store_outlier', $severity, $sku, null, null,
                        "SKU {$sku} at '{$row['location']}' sold " . round($qty) . " units in the last {$days} days — "
                        . round($dropPct) . "% below the cross-location average of " . round($avg) . " units.",
                        ['location' => $row['location'], 'location_qty' => $qty, 'avg_qty' => round($avg, 1), 'drop_pct' => round($dropPct, 1), 'days' => $days,
                         'revenue_impact' => round($value, 2)]
                    );
                }
            }
        }
    }

    // =========================================================================
    // OPERATIONAL / DATA QUALITY
    // =========================================================================

    private function detectImportFrequencyGap(int $tenantId, array $thresholds): void
    {
        $days = (int)($thresholds['days'] ?? 7);

        $latestImport = Import::where('tenant_id', $tenantId)
            ->whereIn('status', [Import::STATUS_COMPLETED, Import::STATUS_COMPLETED_WITH_ERRORS])
            ->orderByDesc('updated_at')
            ->first();

        if (!$latestImport) {
            $this->flag($tenantId, 'import_frequency_gap', 'medium', null, null, null,
                "No data has ever been successfully imported for this tenant.",
                ['days_threshold' => $days]
            );
            return;
        }

        $daysSince = Carbon::parse($latestImport->updated_at)->diffInDays(Carbon::now());

        if ($daysSince >= $days) {
            $lastDate = Carbon::parse($latestImport->updated_at)->format('Y-m-d');
            $this->flag($tenantId, 'import_frequency_gap', 'medium', null, null, null,
                "No data import completed in {$daysSince} day(s) — last import was {$lastDate}.",
                ['days_since_last_import' => $daysSince, 'last_import_at' => $lastDate, 'days_threshold' => $days]
            );
        }
    }

    /**
     * WP3.1 (audit C2): each receipt LINE is its own row now, so a receipt id on
     * several rows is normal and a re-sent (receipt, line) is skipped at import.
     * The remaining duplicate-load signal is a receipt whose lines arrived
     * through more than one import (the same sales loaded twice with different
     * line numbering). Newest first, capped per run.
     */
    private function detectDuplicateTransactionIds(int $tenantId): void
    {
        $duplicates = SalesTransaction::where('tenant_id', $tenantId)
            ->whereNotNull('transaction_id')
            ->whereNotNull('import_id')
            ->selectRaw('transaction_id, COUNT(*) as cnt, COUNT(DISTINCT import_id) as imports, MIN(sku) as sku')
            ->groupBy('transaction_id')
            ->havingRaw('COUNT(DISTINCT import_id) > 1')    // PostgreSQL does not support alias references in HAVING
            ->orderByRaw('MAX(id) DESC')
            ->limit(50)
            ->get();

        foreach ($duplicates as $dup) {
            $this->flag($tenantId, 'duplicate_transaction_ids', 'high', $dup->sku, null, null,
                "Receipt '{$dup->transaction_id}' was loaded by {$dup->imports} different imports ({$dup->cnt} lines) — possible duplicate import.",
                ['transaction_id' => $dup->transaction_id, 'count' => $dup->cnt, 'imports' => $dup->imports]
            );
        }
    }

    private function detectSkuMasterDrift(int $tenantId): void
    {
        $knownSkus = Product::where('tenant_id', $tenantId)->pluck('sku')->unique();

        $salesSkus     = SalesTransaction::where('tenant_id', $tenantId)->pluck('sku')->unique();
        $inventorySkus = InventoryLevel::where('tenant_id', $tenantId)->pluck('sku')->unique();
        $allDataSkus   = $salesSkus->merge($inventorySkus)->unique();

        foreach ($allDataSkus->diff($knownSkus) as $sku) {
            $inSales     = $salesSkus->contains($sku);
            $inInventory = $inventorySkus->contains($sku);
            $sources     = array_values(array_filter([
                $inSales     ? 'sales' : null,
                $inInventory ? 'inventory' : null,
            ]));

            $this->flag($tenantId, 'sku_master_drift', 'low', $sku, null, null,
                "SKU {$sku} appears in " . implode(' and ', $sources) . " data but has no product master record.",
                ['sku' => $sku, 'found_in' => $sources]
            );
        }
    }

    private function detectLocationProliferation(int $tenantId, array $thresholds): void
    {
        $days  = (int)($thresholds['days'] ?? 7);
        $since = $this->analysisDate()->subDays($days)->format('Y-m-d');

        $newStores = Store::where('tenant_id', $tenantId)
            ->where('created_at', '>=', $since)
            ->whereNull('code')
            ->whereNull('city')
            ->whereNull('address')
            ->get();

        foreach ($newStores as $store) {
            $this->flag($tenantId, 'location_proliferation', 'low', null, $store->id, null,
                "New location '{$store->name}' was auto-created from an import with no enrichment data — verify it is not a duplicate or typo.",
                ['store_name' => $store->name, 'created_at' => $store->created_at?->format('Y-m-d')]
            );
        }
    }

    // =========================================================================
    // SHARED HELPERS
    // =========================================================================

    /**
     * Returns [recentSalesBySku, historicalSalesBySku, numComparablePeriods]
     */
    /**
     * Store-level recent-vs-historical sales, keyed "store_id|sku". Powers the
     * optional store-level sales pass (a real single-store swing is diluted by
     * the tenant-wide comparison across all stores). SKU-scoped in incremental mode.
     *
     * @return array{0:\Illuminate\Support\Collection,1:\Illuminate\Support\Collection,2:int}
     */
    private function salesComparisonByStore(int $tenantId, int $days): array
    {
        $recentStart = $this->analysisDate()->subDays($days)->format('Y-m-d');
        $histEnd     = $this->analysisDate()->subDays($days)->format('Y-m-d');
        $histStart   = $this->analysisDate()->subDays($days + 28)->format('Y-m-d');

        $agg = fn ($q) => $q->whereNotNull('store_id')
            ->when($this->scope, fn ($qq) => $this->scope->constrain($qq))
            ->selectRaw('store_id, sku, SUM(quantity) as qty')
            ->groupBy('store_id', 'sku')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->store_id . '|' . $r->sku => (float) $r->qty]);

        $recent     = $agg(SalesTransaction::where('tenant_id', $tenantId)->where('date', '>=', $recentStart));
        $historical = $agg(SalesTransaction::where('tenant_id', $tenantId)->whereBetween('date', [$histStart, $histEnd]));

        return [$recent, $historical, max(1, (int) round(28 / $days))];
    }

    /**
     * Optional store-level pass for sales_spike / sales_drop. Off by default
     * (`store_level` threshold); gated by min_revenue AND a minimum absolute-units
     * floor (`store_min_units`) so a small store's normal wobble doesn't flood the
     * worklist. Flags per (store, sku) — distinct from the tenant-wide anomaly.
     */
    private function storeSalesSwing(int $tenantId, string $ruleType, array $thresholds, bool $isDrop): void
    {
        $pct        = (float) ($thresholds['pct'] ?? ($isDrop ? 30 : 50));
        $days       = (int) ($thresholds['days'] ?? 7);
        $minRevenue = (float) ($thresholds['min_revenue'] ?? self::DEFAULT_MIN_REVENUE);
        $minUnits   = (float) ($thresholds['store_min_units'] ?? 20); // anti-flood: real per-store volume only

        [$recent, $historical, $periods] = $this->salesComparisonByStore($tenantId, $days);

        foreach (($isDrop ? $historical : $recent)->keys() as $key) {
            $parts = explode('|', (string) $key, 2);
            if (count($parts) !== 2 || $parts[1] === '') {
                continue;
            }
            [$storeId, $sku] = $parts;

            $histQty   = (float) ($historical->get($key) ?? 0);
            $recentQty = (float) ($recent->get($key) ?? 0);
            if ($histQty <= 0) {
                continue;
            }
            $avgQty = $histQty / $periods;

            $changePct = $isDrop ? (($avgQty - $recentQty) / $avgQty) * 100 : (($recentQty - $avgQty) / $avgQty) * 100;
            $units     = $isDrop ? max(0.0, $avgQty - $recentQty) : max(0.0, $recentQty - $avgQty);
            if ($changePct < $pct || $units < $minUnits) {
                continue;
            }

            $price  = $this->unitPrice($sku);
            $impact = $units * $price;
            if ($price > 0 && $impact < $minRevenue) {
                continue;
            }
            $severity = $price > 0
                ? $this->severityFromImpact($impact)
                : ($isDrop ? Anomaly::SEVERITY_MEDIUM : Anomaly::SEVERITY_LOW);

            $this->flag($tenantId, $ruleType, $severity, $sku, (int) $storeId, null,
                "SKU {$sku} at store {$storeId} " . ($isDrop ? 'dropped' : 'spiked') . ' ' . round(abs($changePct))
                . "% vs its {$days}-day average (recent: " . round($recentQty) . ', avg: ' . round($avgQty)
                . ' units) — a store-level swing the tenant-wide view dilutes.',
                ['scope' => 'store', 'store_id' => (int) $storeId, 'recent_qty' => $recentQty,
                 'avg_qty' => round($avgQty, 2), 'change_pct' => round($changePct, 1),
                 'units' => round($units, 1), 'days' => $days, 'revenue_impact' => round($impact, 2)]
            );
        }
    }

    private function salesComparison(int $tenantId, int $days): array
    {
        $recentStart = $this->analysisDate()->subDays($days)->format('Y-m-d');
        $histEnd     = $this->analysisDate()->subDays($days)->format('Y-m-d');
        $histStart   = $this->analysisDate()->subDays($days + 28)->format('Y-m-d');

        $recent = SalesTransaction::where('tenant_id', $tenantId)
            ->where('date', '>=', $recentStart)
            ->when($this->scope, fn ($q) => $this->scope->constrain($q))
            ->selectRaw('sku, SUM(quantity) as qty')
            ->groupBy('sku')
            ->pluck('qty', 'sku')
            ->map(fn ($v) => (float) $v);

        $historical = SalesTransaction::where('tenant_id', $tenantId)
            ->whereBetween('date', [$histStart, $histEnd])
            ->when($this->scope, fn ($q) => $this->scope->constrain($q))
            ->selectRaw('sku, SUM(quantity) as qty')
            ->groupBy('sku')
            ->pluck('qty', 'sku')
            ->map(fn ($v) => (float) $v);

        $numPeriods = max(1, (int) round(28 / $days));

        return [$recent, $historical, $numPeriods];
    }

    /**
     * Upsert an anomaly: update description/context/severity on existing open anomaly (by rule+sku+store),
     * preserving all investigation fields. Creates fresh if none found.
     * Tracks the anomaly ID so stale anomalies can be removed after the rule runs.
     */
    private function flag(
        int $tenantId,
        string $ruleType,
        string $severity,
        ?string $sku,
        ?int $storeId,
        ?int $productId,
        string $description,
        array $context = [],
        ?string $subject = null,
    ): void {
        // Best-fit gate (Phase 3): skip rules that don't fit this item's demand
        // segment. Not "touching" the anomaly here means a pre-existing one is
        // cleaned up by the per-rule stale sweep — so gating also clears anomalies
        // that no longer belong.
        if (! $this->ruleAppliesTo($ruleType, $sku, $storeId)) {
            $this->gatedFlags++;
            $this->gatedByRule[$ruleType] = ($this->gatedByRule[$ruleType] ?? 0) + 1;
            return;
        }

        if ($this->v2) {
            $this->flagV2($tenantId, $ruleType, $severity, $sku, $storeId, $productId, $description, $context, $subject);

            return;
        }

        $this->emittedByRule[$ruleType] = ($this->emittedByRule[$ruleType] ?? 0) + 1;

        if ($this->capture !== null) {
            $this->capture[] = $this->captureRow($tenantId, $ruleType, $severity, $sku, $storeId, $context, null);

            return;
        }

        $anomaly = null;

        // For SKU-based rules, look for an existing open anomaly to update rather than recreate.
        // This preserves investigation work (ai_what, ai_why, action_notes, etc.) when a condition persists overnight.
        //
        // M15 dedup key fix: store_id is ALWAYS part of the dedup key.
        // Store A stockout and Store B stockout are separate operational incidents.
        // NULL store_id is a valid distinct key (non-store-specific rules stay grouped).
        if ($sku !== null) {
            $query = Anomaly::where('tenant_id', $tenantId)
                ->where('rule_type', $ruleType)
                ->where('sku', $sku)
                ->whereNull('dismissed_at')
                // Recovery lifecycle (R2): never reopen a RESOLVED episode — a
                // subject that fails again after recovery is a fresh episode
                // (new row), so recovery history stays intact.
                ->where('lifecycle_state', '!=', Anomaly::LIFECYCLE_RESOLVED);

            if ($storeId !== null) {
                $query->where('store_id', $storeId);
            } else {
                $query->whereNull('store_id');
            }

            $anomaly = $query->first();
        }

        if ($anomaly) {
            // Update detection fields only — never overwrite investigation fields
            $anomaly->update([
                'severity'    => $severity,
                'description' => $description,
                'context'     => $context,
                'product_id'  => $productId,
                'store_id'    => $storeId,
            ]);
        } else {
            $anomaly = Anomaly::create([
                'tenant_id'   => $tenantId,
                'rule_type'   => $ruleType,
                'severity'    => $severity,
                'sku'         => $sku,
                'store_id'    => $storeId,
                'product_id'  => $productId,
                'description' => $description,
                'context'     => $context,
                'detected_at' => now(),
            ]);
        }

        $this->touchedAnomalyIds[] = $anomaly->id;
    }

    /**
     * WP4.1 / WP4.3 (audit C10, H14) — v2 upsert by identity.
     *
     * The subject's identity (tenant, rule, store, SKU and — for rules whose
     * subject is a PO, supplier or receipt — that discriminator) finds its
     * latest episode, so SKU-less rules update one row instead of adding one
     * each night. A dismissed episode stays quiet while the condition
     * persists, unless it materially worsens (value × dismissal_worsen_factor,
     * or a higher severity) or detection.dismissal_max_days have passed; then
     * a new episode opens. A resolved episode is never reopened.
     */
    private function flagV2(
        int $tenantId,
        string $ruleType,
        string $severity,
        ?string $sku,
        ?int $storeId,
        ?int $productId,
        string $description,
        array $context,
        ?string $subject,
    ): void {
        if ($subject !== null && trim($subject) !== '') {
            $context['subject'] = trim($subject);
        }
        $key = \App\Services\Recovery\AnomalyIdentity::key($tenantId, $ruleType, $storeId, $sku, $context['subject'] ?? null);

        $latest = Anomaly::where('tenant_id', $tenantId)
            ->where('identity_key', $key)
            ->orderByDesc('episode_seq')
            ->orderByDesc('id')
            ->first();

        if ($latest && $latest->dismissed_at !== null
            && $latest->dismiss_reason !== AnomalyDismissal::REASON_SUPERSEDED
            && $latest->lifecycle_state !== Anomaly::LIFECYCLE_RESOLVED
            && ! $this->worsenedSinceDismissal($latest, $severity, $context)) {
            $this->suppressedByRule[$ruleType] = ($this->suppressedByRule[$ruleType] ?? 0) + 1;

            return;
        }

        $this->emittedByRule[$ruleType] = ($this->emittedByRule[$ruleType] ?? 0) + 1;

        if ($this->capture !== null) {
            $this->capture[] = $this->captureRow($tenantId, $ruleType, $severity, $sku, $storeId, $context, $key);

            return;
        }

        $open = $latest && $latest->dismissed_at === null && $latest->lifecycle_state !== Anomaly::LIFECYCLE_RESOLVED;

        if ($open) {
            $latest->update([
                'severity'    => $severity,
                'description' => $description,
                'context'     => $context,
                'product_id'  => $productId ?? $latest->product_id,
                'value_type'  => ValueModel::type($ruleType, $context),
            ]);
            $anomaly = $latest;
        } else {
            $anomaly = Anomaly::create([
                'tenant_id'    => $tenantId,
                'rule_type'    => $ruleType,
                'severity'     => $severity,
                'sku'          => $sku,
                'store_id'     => $storeId,
                'product_id'   => $productId,
                'description'  => $description,
                'context'      => $context,
                'detected_at'  => now(),
                'identity_key' => $key,
                // WP4.4: the figure at open is whatever money the rule reports
                // (lost revenue, stock value, goods value…); value_type says which.
                'value_type'    => ValueModel::type($ruleType, $context),
                'value_at_open' => ValueModel::amount($context),
            ]);
        }

        $this->touchedAnomalyIds[] = $anomaly->id;
    }

    private const SEVERITY_RANK = [Anomaly::SEVERITY_LOW => 1, Anomaly::SEVERITY_MEDIUM => 2, Anomaly::SEVERITY_HIGH => 3];

    /** Has a dismissed subject become materially worse (or has the dismissal expired)? */
    private function worsenedSinceDismissal(Anomaly $dismissed, string $severity, array $context): bool
    {
        $maxDays = (int) config('detection.dismissal_max_days', 90);
        if ($dismissed->dismissed_at->lt(now()->subDays($maxDays))) {
            return true;
        }
        if ((self::SEVERITY_RANK[$severity] ?? 0) > (self::SEVERITY_RANK[$dismissed->severity] ?? 0)) {
            return true;
        }
        $before = self::contextValue(is_array($dismissed->context) ? $dismissed->context : []);
        $now    = self::contextValue($context);

        return $before > 0 && $now >= $before * (float) config('detection.dismissal_worsen_factor', 1.5);
    }

    /** The money figure a rule reports in its context (lost revenue, stock value, goods value…). */
    private static function contextValue(array $context): float
    {
        return ValueModel::amount($context);
    }

    /** @return array<string,mixed> */
    private function captureRow(int $tenantId, string $ruleType, string $severity, ?string $sku, ?int $storeId, array $context, ?string $key): array
    {
        return [
            'rule'     => $ruleType,
            'identity' => $key ?? \App\Services\Recovery\AnomalyIdentity::key($tenantId, $ruleType, $storeId, $sku, $context['subject'] ?? null),
            'sku'      => $sku,
            'store_id' => $storeId,
            'subject'  => $context['subject'] ?? null,
            'severity' => $severity,
            'value'    => self::contextValue($context),
            'type'     => ValueModel::type($ruleType, $context),
        ];
    }
}
