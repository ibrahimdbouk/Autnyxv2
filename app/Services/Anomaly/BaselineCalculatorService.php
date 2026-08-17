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
     * Called nightly by baselines:compute.
     */
    public function computeForTenant(int $tenantId): void
    {
        $since = Carbon::today()->subDays(90)->format('Y-m-d');

        // --- sales_spike / sales_drop: daily_sales_qty per SKU ---
        $dailySalesBySku = SalesTransaction::where('tenant_id', $tenantId)
            ->where('date', '>=', $since)
            ->where('quantity', '>', 0)
            ->selectRaw("sku, TO_CHAR(date::date, 'YYYY-MM-DD') as day, SUM(quantity) as daily_qty")
            ->groupByRaw("sku, TO_CHAR(date::date, 'YYYY-MM-DD')")
            ->get()
            ->groupBy('sku');

        foreach ($dailySalesBySku as $sku => $rows) {
            $values = $rows->pluck('daily_qty')->map(fn ($v) => (float) $v)->values()->all();
            $this->upsertBaseline($tenantId, $sku, 'sales_spike', 'daily_sales_qty', $values);
            $this->upsertBaseline($tenantId, $sku, 'sales_drop',  'daily_sales_qty', $values);
        }

        // --- price_anomaly: unit_price samples per SKU ---
        $pricesBySku = SalesTransaction::where('tenant_id', $tenantId)
            ->where('date', '>=', $since)
            ->whereNotNull('unit_price')
            ->where('unit_price', '>', 0)
            ->selectRaw('sku, unit_price')
            ->get()
            ->groupBy('sku');

        foreach ($pricesBySku as $sku => $rows) {
            $values = $rows->pluck('unit_price')->map(fn ($v) => (float) $v)->values()->all();
            $this->upsertBaseline($tenantId, $sku, 'price_anomaly', 'unit_price', $values);
        }

        // --- store_outlier: location_qty per SKU across locations (90-day totals) ---
        $locationQtiesBySku = SalesTransaction::where('tenant_id', $tenantId)
            ->where('date', '>=', $since)
            ->whereNotNull('location')
            ->selectRaw('sku, location, SUM(quantity) as qty')
            ->groupBy('sku', 'location')
            ->get()
            ->groupBy('sku');

        foreach ($locationQtiesBySku as $sku => $rows) {
            $values = $rows->pluck('qty')->map(fn ($v) => (float) $v)->values()->all();
            $this->upsertBaseline($tenantId, $sku, 'store_outlier', 'location_qty', $values);
        }

        Log::info("[M9] Baselines computed for tenant {$tenantId}");
    }

    // =========================================================================
    // LOOKUP & SCORING
    // =========================================================================

    /**
     * Retrieve the stored baseline for a given SKU + rule + metric.
     * Returns null when no baseline has been computed yet (fall back to fixed-pct).
     */
    public function getBaseline(int $tenantId, ?string $sku, string $ruleType, string $metric): ?SkuBaseline
    {
        return SkuBaseline::where('tenant_id', $tenantId)
            ->where('sku', $sku)
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
     */
    private function upsertBaseline(
        int $tenantId,
        ?string $sku,
        string $ruleType,
        string $metric,
        array $values
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
