<?php

namespace App\Services\Anomaly;

use App\Models\SkuProfile;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds the per (SKU, store) behavioural profile that the best-fit detection
 * layer stands on. One grouped pass over sales_daily yields the demand shape
 * (Syntetos–Boylan classification via ADI + CV²), the trend, volume tier and
 * lifecycle; a latest-on-hand pass adds dead-stock items that never sell.
 *
 * Phase 2: this only WRITES `sku_profiles`. Detection does not read it yet.
 */
class SkuProfilerService
{
    /** Syntetos–Boylan cut points. */
    private const ADI_CUT = 1.32;
    private const CV2_CUT = 0.49;

    /** Below this many selling days in the window we can't classify a shape. */
    private const MIN_DAYS_TO_CLASSIFY = 4;

    public function profileForTenant(int $tenantId, int $windowDays = 90): int
    {
        @ini_set('memory_limit', '768M');

        $from    = Carbon::today()->subDays($windowDays)->format('Y-m-d');
        $newFrom = Carbon::today()->subDays(21)->format('Y-m-d');
        $now     = Carbon::now();

        // 1. Demand statistics per (store, sku) — one aggregate pass.
        $sales = DB::select(
            "SELECT store_id, sku,
                    COUNT(*)                       AS selling_days,
                    SUM(units_sold)                AS total_units,
                    SUM(revenue)                   AS total_revenue,
                    AVG(units_sold)                AS mean_nz,
                    STDDEV_SAMP(units_sold)        AS sd_nz,
                    MIN(date)                      AS first_sold,
                    regr_slope(units_sold, EXTRACT(EPOCH FROM date)/86400.0) AS slope,
                    regr_r2(units_sold,    EXTRACT(EPOCH FROM date)/86400.0) AS r2
             FROM sales_daily
             WHERE tenant_id = ? AND date >= ?
             GROUP BY store_id, sku",
            [$tenantId, $from]
        );

        $profiles = [];   // "store|sku" => row array ("0|sku" = chain-level)
        $revenues = [];   // "store|sku" => revenue (for volume tiers)

        foreach ($sales as $r) {
            $key            = $r->store_id . '|' . $r->sku;
            $profiles[$key] = $this->makeRow($tenantId, (string) $r->sku, (int) $r->store_id, $r, $windowDays, $newFrom, $now);
            $revenues[$key] = (float) $r->total_revenue;
        }

        // 1b. Chain-level demand shape per SKU (store_id = 0 sentinel), from
        //     daily totals across all stores. Tenant-wide demand rules gate on
        //     this: a SKU that's intermittent at one store may be a frequent
        //     seller chain-wide, where sales_drop/spike DO make sense.
        $chain = DB::select(
            "SELECT sku,
                    COUNT(*)                AS selling_days,
                    SUM(daily_units)        AS total_units,
                    SUM(daily_rev)          AS total_revenue,
                    AVG(daily_units)        AS mean_nz,
                    STDDEV_SAMP(daily_units) AS sd_nz,
                    MIN(date)               AS first_sold,
                    regr_slope(daily_units, EXTRACT(EPOCH FROM date)/86400.0) AS slope,
                    regr_r2(daily_units,    EXTRACT(EPOCH FROM date)/86400.0) AS r2
             FROM (
                 SELECT sku, date, SUM(units_sold) AS daily_units, SUM(revenue) AS daily_rev
                 FROM sales_daily
                 WHERE tenant_id = ? AND date >= ?
                 GROUP BY sku, date
             ) t
             GROUP BY sku",
            [$tenantId, $from]
        );

        foreach ($chain as $r) {
            $profiles['0|' . $r->sku] = $this->makeRow($tenantId, (string) $r->sku, 0, $r, $windowDays, $newFrom, $now);
            // chain rows excluded from store-level volume tiering (revenues not added)
        }

        // 2. Latest on-hand per (store, sku): mark has_inventory, and add
        //    dead-stock profiles (stock on hand, no sales in the window).
        $inv = DB::select(
            "SELECT DISTINCT ON (store_id, sku) store_id, sku, on_hand_qty
             FROM inventory_levels
             WHERE tenant_id = ? AND store_id IS NOT NULL
             ORDER BY store_id, sku, as_of_date DESC NULLS LAST",
            [$tenantId]
        );

        foreach ($inv as $r) {
            $key    = $r->store_id . '|' . $r->sku;
            $onHand = (float) $r->on_hand_qty;

            if (isset($profiles[$key])) {
                $profiles[$key]['has_inventory'] = $onHand > 0;
            } elseif ($onHand > 0) {
                $profiles[$key] = [
                    'tenant_id'     => $tenantId,
                    'sku'           => $r->sku,
                    'store_id'      => (int) $r->store_id,
                    'segment'       => SkuProfile::SEG_DEAD,
                    'lifecycle'     => 'mature',
                    'chosen_model'  => SkuProfile::MODEL_NONE,
                    'window_days'   => $windowDays,
                    'selling_days'  => 0,
                    'total_units'   => 0.0,
                    'total_revenue' => 0.0,
                    'mean_nonzero'  => null,
                    'adi'           => null,
                    'cv2'           => null,
                    'trend_slope'   => null,
                    'trend_r2'      => null,
                    'has_inventory' => true,
                    'computed_at'   => $now,
                ];
                $revenues[$key] = 0.0;
            }
        }

        if (empty($profiles)) return 0;

        // 3. Volume tiers by revenue percentile across the tenant.
        $this->assignVolumeTiers($profiles, $revenues);

        // 4. Bulk upsert in chunks.
        foreach (array_chunk(array_values($profiles), 1000) as $chunk) {
            DB::table('sku_profiles')->upsert(
                $chunk,
                ['tenant_id', 'sku', 'store_id'],
                ['segment', 'volume_tier', 'lifecycle', 'chosen_model', 'window_days',
                 'selling_days', 'total_units', 'total_revenue', 'mean_nonzero', 'adi',
                 'cv2', 'trend_slope', 'trend_r2', 'has_inventory', 'computed_at', 'updated_at']
            );
        }

        return count($profiles);
    }

    /** Build a profile row from an aggregate stats object (store- or chain-level). */
    private function makeRow(int $tenantId, string $sku, int $storeId, object $r, int $windowDays, string $newFrom, $now): array
    {
        $sellingDays = (int) $r->selling_days;
        $meanNz      = (float) $r->mean_nz;
        $sd          = $r->sd_nz !== null ? (float) $r->sd_nz : 0.0;
        $adi         = $sellingDays > 0 ? $windowDays / $sellingDays : null;
        $cv2         = $meanNz > 0 ? pow($sd / $meanNz, 2) : 0.0;

        $firstSoldRecent = $r->first_sold !== null
            && substr((string) $r->first_sold, 0, 10) >= $newFrom;

        // NEW = genuinely just appeared (recent first sale) AND little history.
        // A SKU that sells rarely but has done so for months is NOT new — it's
        // intermittent/lumpy, which the ADI/CV² classifier captures (a sparse
        // long-tail seller with ~2 sales in 90d has ADI≈45 → intermittent).
        $segment = ($firstSoldRecent && $sellingDays < self::MIN_DAYS_TO_CLASSIFY)
            ? SkuProfile::SEG_NEW
            : $this->classify($adi, $cv2);

        $slope = $r->slope !== null ? (float) $r->slope : null;
        $r2    = $r->r2 !== null ? (float) $r->r2 : null;

        $lifecycle = 'mature';
        if ($firstSoldRecent) {
            $lifecycle = 'new';
        } elseif ($slope !== null && $slope < 0 && $r2 !== null && $r2 >= 0.3) {
            $lifecycle = 'declining';
        }

        return [
            'tenant_id'     => $tenantId,
            'sku'           => $sku,
            'store_id'      => $storeId,
            'segment'       => $segment,
            'volume_tier'   => null,
            'lifecycle'     => $lifecycle,
            'chosen_model'  => $this->modelFor($segment),
            'window_days'   => $windowDays,
            'selling_days'  => $sellingDays,
            'total_units'   => (float) $r->total_units,
            'total_revenue' => (float) $r->total_revenue,
            'mean_nonzero'  => round($meanNz, 4),
            'adi'           => $adi !== null ? round($adi, 4) : null,
            'cv2'           => round($cv2, 4),
            'trend_slope'   => $slope !== null ? round($slope, 6) : null,
            'trend_r2'      => $r2 !== null ? round($r2, 4) : null,
            'has_inventory' => false,
            'computed_at'   => $now,
        ];
    }

    private function classify(?float $adi, float $cv2): string
    {
        if ($adi === null) return SkuProfile::SEG_UNKNOWN;

        $intermittent = $adi >= self::ADI_CUT;
        $variable     = $cv2 >= self::CV2_CUT;

        if (! $intermittent && ! $variable) return SkuProfile::SEG_SMOOTH;
        if (! $intermittent &&   $variable) return SkuProfile::SEG_ERRATIC;
        if ($intermittent   && ! $variable) return SkuProfile::SEG_INTERMITTENT;

        return SkuProfile::SEG_LUMPY;
    }

    private function modelFor(string $segment): string
    {
        return match ($segment) {
            SkuProfile::SEG_SMOOTH       => SkuProfile::MODEL_MOVING_AVERAGE,
            SkuProfile::SEG_ERRATIC      => SkuProfile::MODEL_SES,
            SkuProfile::SEG_INTERMITTENT => SkuProfile::MODEL_CROSTON,
            SkuProfile::SEG_LUMPY        => SkuProfile::MODEL_SBA,
            SkuProfile::SEG_DEAD         => SkuProfile::MODEL_NONE,
            default                      => SkuProfile::MODEL_MOVING_AVERAGE,
        };
    }

    /** A = top 20% of revenue, B = next 30%, C = the rest (0-revenue = C). */
    private function assignVolumeTiers(array &$profiles, array $revenues): void
    {
        $sorted = array_values(array_filter($revenues, fn ($v) => $v > 0));
        sort($sorted);
        $n = count($sorted);
        if ($n === 0) {
            foreach ($profiles as $k => &$p) $p['volume_tier'] = 'C';
            return;
        }
        $p80 = $sorted[(int) floor(0.80 * ($n - 1))];
        $p50 = $sorted[(int) floor(0.50 * ($n - 1))];

        foreach ($profiles as $k => &$p) {
            $rev = $revenues[$k] ?? 0.0;
            $p['volume_tier'] = $rev >= $p80 ? 'A' : ($rev >= $p50 ? 'B' : 'C');
        }
    }
}
