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
        $from    = Carbon::today()->subDays($windowDays)->format('Y-m-d');
        $newFrom = Carbon::today()->subDays(21)->format('Y-m-d');
        $now     = Carbon::now();

        // WP6.2: current positions, lots summed.
        app(\App\Services\Inventory\InventoryCurrentService::class)->ensure($tenantId);

        // WP6.3 (audit H31): streamed and written in chunks of 1,000 — the
        // profiler used to hold one PHP row per (store, SKU) for the whole
        // tenant and ran out of memory at a few hundred thousand positions.

        // Volume tier cut-offs over the store-level revenues (A = top 20%,
        // B = next 30%, C = the rest; zero revenue is C), computed in SQL.
        [$p80, $p50] = $this->tierCutoffs($tenantId, $from);

        // W10: intermittency is measured over the history there IS. A tenant
        // with 35 days of data used to divide by the 90-day window, so every
        // daily seller looked intermittent (ADI 2.6) and the demand rules were
        // gated off for its first ~2 months.
        $span = DB::selectOne('SELECT MIN(date) AS a, MAX(date) AS b FROM sales_daily WHERE tenant_id = ? AND date >= ?', [$tenantId, $from]);
        $spanDays = $span && $span->a
            ? max(1, Carbon::parse($span->a)->diffInDays(Carbon::parse($span->b), true) + 1)
            : $windowDays;
        $spanDays = (int) min($windowDays, $spanDays);
        $tier = fn (float $rev) => ($p80 === null) ? 'C' : ($rev >= $p80 ? 'A' : ($rev >= $p50 ? 'B' : 'C'));

        $buffer = [];
        $written = 0;
        $flush = function () use (&$buffer, &$written) {
            if ($buffer === []) {
                return;
            }
            DB::table('sku_profiles')->upsert(
                $buffer,
                ['tenant_id', 'sku', 'store_id'],
                ['segment', 'volume_tier', 'lifecycle', 'chosen_model', 'window_days',
                 'selling_days', 'total_units', 'total_revenue', 'mean_nonzero', 'adi',
                 'cv2', 'trend_slope', 'trend_r2', 'has_inventory', 'computed_at', 'updated_at']
            );
            $written += count($buffer);
            $buffer = [];
        };
        $push = function (array $row) use (&$buffer, $flush, $now) {
            $buffer[] = $row + ['created_at' => $now, 'updated_at' => $now];
            if (count($buffer) >= 1000) {
                $flush();
            }
        };

        // 1. Demand statistics per (store, sku) — one aggregate pass, streamed;
        //    has_inventory from the current position.
        $sales = DB::cursor(
            "SELECT s.store_id, s.sku, s.selling_days, s.total_units, s.total_revenue, s.mean_nz, s.sd_nz, s.first_sold,
                    s.slope, s.r2, COALESCE(c.on_hand_qty, 0) > 0 AS has_inventory
             FROM (
                SELECT store_id, sku,
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
                GROUP BY store_id, sku
             ) s
             LEFT JOIN inventory_current c ON c.tenant_id = ? AND c.store_id = s.store_id AND c.sku = s.sku",
            [$tenantId, $from, $tenantId]
        );
        foreach ($sales as $r) {
            $row = $this->makeRow($tenantId, (string) $r->sku, (int) $r->store_id, $r, $windowDays, $newFrom, $now, $spanDays);
            $row['has_inventory'] = (bool) $r->has_inventory;
            $row['volume_tier']   = $tier((float) $r->total_revenue);
            $push($row);
        }

        // 1b. Chain-level demand shape per SKU (store_id = 0 sentinel), from
        //     daily totals across all stores. Tenant-wide demand rules gate on
        //     this: a SKU that's intermittent at one store may be a frequent
        //     seller chain-wide, where sales_drop/spike DO make sense.
        //     Chain rows are not part of the store-level volume tiering (C).
        $chain = DB::cursor(
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
            $row = $this->makeRow($tenantId, (string) $r->sku, 0, $r, $windowDays, $newFrom, $now, $spanDays);
            $row['volume_tier'] = $tier(0.0);
            $push($row);
        }

        // 2. Dead-stock profiles: stock on hand, no sale in the window.
        $dead = DB::cursor(
            "SELECT c.store_id, c.sku FROM inventory_current c
              WHERE c.tenant_id = ? AND c.on_hand_qty > 0
                AND NOT EXISTS (SELECT 1 FROM sales_daily s WHERE s.tenant_id = c.tenant_id AND s.store_id = c.store_id
                                   AND s.sku = c.sku AND s.date >= ?)",
            [$tenantId, $from]
        );
        foreach ($dead as $r) {
            $push([
                'tenant_id'     => $tenantId,
                'sku'           => $r->sku,
                'store_id'      => (int) $r->store_id,
                'segment'       => SkuProfile::SEG_DEAD,
                'volume_tier'   => $tier(0.0),
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
            ]);
        }

        $flush();

        return $written;
    }

    /**
     * The revenue at the 80th and 50th percentile of the positive store-level
     * revenues (same positions as the in-memory sort it replaces). [null, null]
     * when no position earned anything.
     *
     * @return array{0:?float,1:?float}
     */
    private function tierCutoffs(int $tenantId, string $from): array
    {
        $revs = "SELECT SUM(revenue) AS rev FROM sales_daily WHERE tenant_id = ? AND date >= ? GROUP BY store_id, sku HAVING SUM(revenue) > 0";
        $n = (int) DB::selectOne("SELECT COUNT(*) AS n FROM ({$revs}) r", [$tenantId, $from])->n;
        if ($n === 0) {
            return [null, null];
        }
        $at = fn (float $q) => (float) DB::selectOne(
            "SELECT rev FROM ({$revs}) r ORDER BY rev OFFSET " . (int) floor($q * ($n - 1)) . ' LIMIT 1',
            [$tenantId, $from]
        )->rev;

        return [$at(0.80), $at(0.50)];
    }

    /** Build a profile row from an aggregate stats object (store- or chain-level). */
    private function makeRow(int $tenantId, string $sku, int $storeId, object $r, int $windowDays, string $newFrom, $now, ?int $spanDays = null): array
    {
        $sellingDays = (int) $r->selling_days;
        $meanNz      = (float) $r->mean_nz;
        $sd          = $r->sd_nz !== null ? (float) $r->sd_nz : 0.0;
        $adi         = $sellingDays > 0 ? max(1.0, ($spanDays ?? $windowDays) / $sellingDays) : null;
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
}
