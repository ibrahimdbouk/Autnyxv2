<?php

namespace App\Services\Anomaly;

use App\Models\AnomalySetting;
use App\Models\SkuBaseline;
use App\Models\SalesTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BaselineCalculatorService
{
    /** Widen sensitivity_multiplier by this much per false positive */
    const FP_WIDEN_STEP = 0.2;

    /** Maximum sensitivity_multiplier (z-score threshold ceiling) — WP4.3: 4, was 5. */
    const MAX_SENSITIVITY = 4.0;

    /** Minimum number of data points to compute a meaningful baseline */
    const MIN_SAMPLES = 7;

    // =========================================================================
    // NIGHTLY COMPUTE
    // =========================================================================

    /**
     * Compute / refresh all baselines for a tenant.
     * Called nightly by baselines:compute — must run BEFORE anomaly detection.
     *
     * Computes baselines at two granularities:
     *   - Retailer-wide (store_id = null)  — used as fallback when no store-level data
     *   - Store-level   (store_id = N)     — preferred when available
     */
    public function computeForTenant(int $tenantId): void
    {
        if (AnomalyDetectionService::rulesV2For($tenantId)) {
            $this->computeForTenantV2($tenantId);

            return;
        }

        $since = Carbon::today()->subDays(90)->format('Y-m-d');

        // ── Retailer-wide daily_sales_qty per SKU ────────────────────────────
        $dailySalesBySku = SalesTransaction::where('tenant_id', $tenantId)
            ->where('date', '>=', $since)
            ->where('quantity', '>', 0)
            ->selectRaw("sku, TO_CHAR(date::date, 'YYYY-MM-DD') as day, SUM(quantity) as daily_qty")
            ->groupByRaw("sku, TO_CHAR(date::date, 'YYYY-MM-DD')")
            ->get()
            ->groupBy('sku');

        foreach ($dailySalesBySku as $sku => $rows) {
            $values = $rows->pluck('daily_qty')->map(fn ($v) => (float) $v)->values()->all();
            $this->upsertBaseline($tenantId, $sku, 'sales_spike', 'daily_sales_qty', $values, null);
            $this->upsertBaseline($tenantId, $sku, 'sales_drop',  'daily_sales_qty', $values, null);
        }

        // ── Store-level daily_sales_qty per (SKU, store_id) ─────────────────
        // Preferred over retailer-wide when getBaseline() is called with a store_id.
        $dailySalesBySkuStore = SalesTransaction::where('tenant_id', $tenantId)
            ->where('date', '>=', $since)
            ->where('quantity', '>', 0)
            ->whereNotNull('store_id')
            ->selectRaw("sku, store_id, TO_CHAR(date::date, 'YYYY-MM-DD') as day, SUM(quantity) as daily_qty")
            ->groupByRaw("sku, store_id, TO_CHAR(date::date, 'YYYY-MM-DD')")
            ->get()
            ->groupBy(fn ($r) => $r->sku . '|' . $r->store_id);

        foreach ($dailySalesBySkuStore as $key => $rows) {
            [$sku, $storeId] = explode('|', $key, 2);
            $storeId = (int) $storeId;
            $values  = $rows->pluck('daily_qty')->map(fn ($v) => (float) $v)->values()->all();
            $this->upsertBaseline($tenantId, $sku, 'sales_spike', 'daily_sales_qty', $values, $storeId);
            $this->upsertBaseline($tenantId, $sku, 'sales_drop',  'daily_sales_qty', $values, $storeId);
        }

        // ── Retailer-wide unit_price per SKU ─────────────────────────────────
        $pricesBySku = SalesTransaction::where('tenant_id', $tenantId)
            ->where('date', '>=', $since)
            ->whereNotNull('unit_price')
            ->where('unit_price', '>', 0)
            ->selectRaw('sku, unit_price')
            ->get()
            ->groupBy('sku');

        foreach ($pricesBySku as $sku => $rows) {
            $values = $rows->pluck('unit_price')->map(fn ($v) => (float) $v)->values()->all();
            $this->upsertBaseline($tenantId, $sku, 'price_anomaly', 'unit_price', $values, null);
        }

        // ── Retailer-wide location_qty per SKU (for store_outlier rule) ──────
        // Uses the cross-location distribution across the 90-day window.
        $locationQtiesBySku = SalesTransaction::where('tenant_id', $tenantId)
            ->where('date', '>=', $since)
            ->whereNotNull('location')
            ->selectRaw('sku, location, SUM(quantity) as qty')
            ->groupBy('sku', 'location')
            ->get()
            ->groupBy('sku');

        foreach ($locationQtiesBySku as $sku => $rows) {
            $values = $rows->pluck('qty')->map(fn ($v) => (float) $v)->values()->all();
            $this->upsertBaseline($tenantId, $sku, 'store_outlier', 'location_qty', $values, null);
        }

        Log::info("[M9/M15] Baselines computed for tenant {$tenantId}");
    }

    // =========================================================================
    // V2 COMPUTE (WP4.2 / audit H15)
    // =========================================================================

    /** v2: days of history a baseline is built from. */
    public const V2_WINDOW_DAYS = 90;

    /** v2: fewer days than this (from a SKU's first sale) → no baseline. */
    public const V2_MIN_DAYS = 14;

    /** v2: sensitivity never widens past this, and drifts back to the default each night. */
    public const V2_MAX_SENSITIVITY = 4.0;
    public const DEFAULT_SENSITIVITY = 2.0;
    public const V2_DECAY = 0.95;

    /**
     * Daily-units baselines for sales_spike / sales_drop, set-based in SQL:
     *
     *   • the 90 days BEFORE the test window (the longest spike/drop `days`),
     *     ending on the sales clock — the days being tested are never part of
     *     their own baseline;
     *   • densified — a day without sales is a 0, counted from the SKU's first
     *     sale (so a new line isn't padded with zeros from before it existed);
     *   • robust — centre = median, spread = 1.4826 × MAD (population σ only
     *     when the MAD is 0), stored in baseline_mean / baseline_stddev;
     *   • chain-level always, store-level when a tenant uses the store pass;
     *   • baselines that no longer qualify are deleted, and sensitivity drifts
     *     back toward the default (× 0.95 of the excess per night).
     *
     * The rules compare the recent MEAN with σ/√n (see DetectsV2::salesSwingPassV2).
     */
    public function computeForTenantV2(int $tenantId): void
    {
        $clock = DB::table('sales_daily')->where('tenant_id', $tenantId)->max('date');
        $clock = $clock ? Carbon::parse($clock)->startOfDay() : Carbon::today();
        if ($clock->gt(Carbon::today())) {
            $clock = Carbon::today();
        }

        $testDays = 7;
        $storeLevel = false;
        foreach (AnomalySetting::where('tenant_id', $tenantId)->whereIn('rule_type', ['sales_spike', 'sales_drop'])->get() as $s) {
            $t = $s->getEffectiveThresholds();
            $testDays = max($testDays, (int) ($t['days'] ?? 7));
            $storeLevel = $storeLevel || (bool) ($t['store_level'] ?? false);
        }

        $until = $clock->copy()->addDay()->subDays($testDays);          // first day of the test window
        $from  = $until->copy()->subDays(self::V2_WINDOW_DAYS);
        $started = now();

        DB::transaction(function () use ($tenantId, $from, $until, $storeLevel, $started) {
            DB::statement('DROP TABLE IF EXISTS tmp_v2_baselines');
            DB::statement('CREATE TEMP TABLE tmp_v2_baselines (store_id bigint, sku varchar(255), centre double precision, spread double precision, n int) ON COMMIT DROP');

            foreach ($storeLevel ? [false, true] : [false] as $byStore) {
                $this->insertRobustStats($tenantId, $from, $until, $byStore);
            }

            foreach (['sales_spike', 'sales_drop'] as $rule) {
                // Update existing rows (store_id matched NULL-safely), then add new ones.
                DB::update(
                    "UPDATE sku_baselines b SET baseline_mean = t.centre, baseline_stddev = t.spread, sample_count = t.n,
                            computed_at = ?, updated_at = ?,
                            sensitivity_multiplier = ? + (b.sensitivity_multiplier - ?) * ?
                     FROM tmp_v2_baselines t
                     WHERE b.tenant_id = ? AND b.rule_type = ? AND b.metric = 'daily_sales_qty'
                       AND b.sku = t.sku AND b.store_id IS NOT DISTINCT FROM t.store_id",
                    [$started, $started, self::DEFAULT_SENSITIVITY, self::DEFAULT_SENSITIVITY, self::V2_DECAY, $tenantId, $rule]
                );
                DB::insert(
                    "INSERT INTO sku_baselines (tenant_id, sku, store_id, rule_type, metric, baseline_mean, baseline_stddev, sample_count,
                                                sensitivity_multiplier, fp_count, computed_at, created_at, updated_at)
                     SELECT ?, t.sku, t.store_id, ?, 'daily_sales_qty', t.centre, t.spread, t.n, ?, 0, ?, ?, ?
                     FROM tmp_v2_baselines t
                     WHERE NOT EXISTS (SELECT 1 FROM sku_baselines b WHERE b.tenant_id = ? AND b.rule_type = ?
                                         AND b.metric = 'daily_sales_qty' AND b.sku = t.sku AND b.store_id IS NOT DISTINCT FROM t.store_id)",
                    [$tenantId, $rule, self::DEFAULT_SENSITIVITY, $started, $started, $started, $tenantId, $rule]
                );
            }

            // No longer qualifying (or no longer used by the v2 rules) → gone.
            DB::table('sku_baselines')->where('tenant_id', $tenantId)
                ->where(fn ($q) => $q->whereIn('rule_type', ['sales_spike', 'sales_drop'])->where('computed_at', '<', $started)
                    ->orWhereIn('rule_type', ['price_anomaly', 'store_outlier']))
                ->delete();
        });

        Log::info("[WP4.2] v2 baselines computed for tenant {$tenantId} over {$from->format('Y-m-d')}..{$until->copy()->subDay()->format('Y-m-d')}");
    }

    /** Median / MAD of the densified daily series per SKU (or store + SKU) into tmp_v2_baselines. */
    private function insertRobustStats(int $tenantId, Carbon $from, Carbon $until, bool $byStore): void
    {
        $key  = $byStore ? 'store_id, sku' : 'sku';
        $sel  = $byStore ? 'store_id' : 'NULL::bigint';
        $join = $byStore ? 'AND s.store_id = d.store_id' : '';

        DB::insert(
            "INSERT INTO tmp_v2_baselines (store_id, sku, centre, spread, n)
             WITH first_sale AS (
                SELECT {$key}, GREATEST(MIN(date), ?::date) AS start
                FROM sales_daily
                WHERE tenant_id = ? AND date < ? " . ($byStore ? 'AND store_id IS NOT NULL ' : '') . "
                GROUP BY {$key}
                HAVING MAX(date) >= ?::date
             ), days AS (
                SELECT {$sel} AS store_id, f.sku, g::date AS d
                FROM first_sale f CROSS JOIN LATERAL generate_series(f.start, ?::date - 1, INTERVAL '1 day') g
             ), series AS (
                SELECT d.store_id, d.sku, d.d, COALESCE(SUM(s.units_sold), 0)::double precision AS u
                FROM days d
                LEFT JOIN sales_daily s ON s.tenant_id = ? AND s.sku = d.sku AND s.date = d.d {$join}
                GROUP BY d.store_id, d.sku, d.d
             ), med AS (
                SELECT store_id, sku, percentile_cont(0.5) WITHIN GROUP (ORDER BY u) AS m, COUNT(*) AS n, stddev_pop(u) AS sd
                FROM series GROUP BY store_id, sku
             ), mad AS (
                SELECT s.store_id, s.sku, percentile_cont(0.5) WITHIN GROUP (ORDER BY ABS(s.u - med.m)) AS mad
                FROM series s JOIN med ON med.sku = s.sku AND med.store_id IS NOT DISTINCT FROM s.store_id
                GROUP BY s.store_id, s.sku
             )
             SELECT med.store_id, med.sku, med.m,
                    CASE WHEN mad.mad > 0 THEN 1.4826 * mad.mad ELSE med.sd END, med.n
             FROM med JOIN mad ON mad.sku = med.sku AND mad.store_id IS NOT DISTINCT FROM med.store_id
             WHERE med.n >= ? AND med.m > 0 AND (mad.mad > 0 OR med.sd >= 0.001)",
            [$from->format('Y-m-d'), $tenantId, $until->format('Y-m-d'), $from->format('Y-m-d'), $until->format('Y-m-d'),
             $tenantId, self::V2_MIN_DAYS]
        );
    }

    // =========================================================================
    // LOOKUP & SCORING
    // =========================================================================

    /**
     * Retrieve the stored baseline for a given SKU + rule + metric.
     *
     * When store_id is provided, tries the store-level baseline first.
     * Falls back to the retailer-wide baseline (store_id = null) when not found.
     * Returns null when neither exists (fall back to fixed-pct in detection rules).
     */
    public function getBaseline(
        int $tenantId,
        ?string $sku,
        string $ruleType,
        string $metric,
        ?int $storeId = null
    ): ?SkuBaseline {
        // Try store-level first when store_id provided
        if ($storeId !== null) {
            $baseline = SkuBaseline::where('tenant_id', $tenantId)
                ->where('sku', $sku)
                ->where('store_id', $storeId)
                ->where('rule_type', $ruleType)
                ->where('metric', $metric)
                ->first();

            if ($baseline) return $baseline;
        }

        // Fall back to retailer-wide (store_id IS NULL)
        return SkuBaseline::where('tenant_id', $tenantId)
            ->where('sku', $sku)
            ->whereNull('store_id')
            ->where('rule_type', $ruleType)
            ->where('metric', $metric)
            ->first();
    }

    /**
     * Compute the z-score of $value against a baseline.
     * z = (value − mean) / stddev
     */
    public function zScore(float $value, SkuBaseline $baseline): float
    {
        if ($baseline->baseline_stddev <= 0) return 0.0;
        return ($value - $baseline->baseline_mean) / $baseline->baseline_stddev;
    }

    // =========================================================================
    // FALSE-POSITIVE FEEDBACK
    // =========================================================================

    /**
     * WP4.3: called only when a person dismisses an anomaly as a FALSE POSITIVE
     * (AnomalyDismissal) or records a false-positive outcome — never inferred
     * from how quickly something was dismissed. Widens the sensitivity by
     * FP_WIDEN_STEP up to MAX_SENSITIVITY for the rule + SKU (store and chain
     * baselines); every change is audited, and v2 baselines drift back toward
     * the default each night.
     */
    public function recordFalsePositive(int $tenantId, string $ruleType, ?string $sku, ?int $userId = null): void
    {
        SkuBaseline::where('tenant_id', $tenantId)
            ->where('rule_type', $ruleType)
            ->where('sku', $sku)
            ->get()
            ->each(function (SkuBaseline $baseline) use ($userId) {
                $before = (float) $baseline->sensitivity_multiplier;
                $after  = min(self::MAX_SENSITIVITY, $before + self::FP_WIDEN_STEP);
                $baseline->update(['sensitivity_multiplier' => $after, 'fp_count' => $baseline->fp_count + 1]);

                \App\Models\AuditLog::create([
                    'tenant_id'   => $baseline->tenant_id,
                    'user_id'     => $userId,
                    'event_type'  => 'baseline_sensitivity_changed',
                    'description' => "False positive on {$baseline->rule_type} for SKU {$baseline->sku}"
                        . ($baseline->store_id ? " (store {$baseline->store_id})" : '') . ': sensitivity '
                        . round($before, 2) . ' → ' . round($after, 2),
                    'old_value'   => ['sensitivity_multiplier' => $before],
                    'new_value'   => ['sensitivity_multiplier' => $after],
                ]);
            });

        Log::info("[M9] FP recorded [{$ruleType}] sku=[{$sku}] tenant={$tenantId}");
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Compute population mean + stddev from $values and upsert the baseline row.
     * Preserves the existing sensitivity_multiplier and fp_count (only updates stats).
     *
     * @param int|null $storeId  null = retailer-wide; non-null = store-level
     */
    private function upsertBaseline(
        int $tenantId,
        ?string $sku,
        string $ruleType,
        string $metric,
        array $values,
        ?int $storeId = null
    ): void {
        $count = count($values);
        if ($count < self::MIN_SAMPLES) return;

        $mean   = array_sum($values) / $count;
        $stddev = $this->populationStddev($values, $mean);

        if ($stddev < 0.001) return; // no meaningful variance — avoid divide-by-zero later

        SkuBaseline::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'sku'       => $sku,
                'store_id'  => $storeId,
                'rule_type' => $ruleType,
                'metric'    => $metric,
            ],
            [
                'baseline_mean'   => $mean,
                'baseline_stddev' => $stddev,
                'sample_count'    => $count,
                'computed_at'     => now(),
            ]
        );
    }

    /**
     * Population standard deviation (N denominator, not N-1).
     */
    private function populationStddev(array $values, float $mean): float
    {
        $count = count($values);
        if ($count < 2) return 0.0;

        $variance = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $values)) / $count;
        return sqrt($variance);
    }
}
