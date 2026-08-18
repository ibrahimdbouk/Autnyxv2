<?php

namespace App\Services\Anomaly;

use App\Models\SkuBaseline;
use App\Models\SalesTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class BaselineCalculatorService
{
    /** Widen sensitivity_multiplier by this much per false positive */
    const FP_WIDEN_STEP = 0.2;

    /** Maximum sensitivity_multiplier (z-score threshold ceiling) */
    const MAX_SENSITIVITY = 5.0;

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
     * Called when an anomaly is dismissed within 10 minutes of detection.
     * Widens the sensitivity_multiplier by FP_WIDEN_STEP (max MAX_SENSITIVITY)
     * so future signals need to be more extreme before firing.
     *
     * Widens both store-level and retailer-wide baselines for the rule+sku combo.
     */
    public function recordFalsePositive(int $tenantId, string $ruleType, ?string $sku): void
    {
        SkuBaseline::where('tenant_id', $tenantId)
            ->where('rule_type', $ruleType)
            ->where('sku', $sku)
            ->get()
            ->each(function (SkuBaseline $baseline) {
                $baseline->update([
                    'sensitivity_multiplier' => min(
                        self::MAX_SENSITIVITY,
                        $baseline->sensitivity_multiplier + self::FP_WIDEN_STEP
                    ),
                    'fp_count' => $baseline->fp_count + 1,
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
