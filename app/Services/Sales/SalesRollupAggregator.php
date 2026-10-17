<?php

namespace App\Services\Sales;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Root Cause drivers — the store and category rollups of the daily sales.
 *
 *  store_daily     units, revenue and SKUs sold per store and day (from
 *                  sales_daily, so in the tenant's currency), plus receipts:
 *                  the distinct receipts of the day's sales lines — the
 *                  store's traffic. Lines without a receipt id are counted
 *                  (lines vs lines_with_receipt) so a reader knows how far the
 *                  receipt count can be trusted.
 *  category_daily  the same per store × department × category, with the
 *                  receipts that contain the category.
 *
 * Units and revenue come from sales_daily, receipts from the raw lines. Days
 * whose raw lines have been purged keep their units and revenue (rebuilt
 * from sales_daily) with no receipt count. Rebuilding a range replaces it,
 * so it is idempotent; a product re-classification is picked up by
 * rebuilding category_daily (RebuildSalesRollupsJob).
 */
class SalesRollupAggregator
{
    public const STORE = 'store';
    public const CATEGORY = 'category';

    /**
     * @param  array<int,string>  $what  store | category
     * @return int rows written
     */
    public function rebuild(int $tenantId, string $from, string $to, array $what = [self::STORE, self::CATEGORY]): int
    {
        if ($from > $to) {
            return 0;
        }
        $now = now()->toDateTimeString();
        $n = 0;

        DB::transaction(function () use ($tenantId, $from, $to, $what, $now, &$n) {
            // One grouped pass over a UNION of the daily rows (units, revenue,
            // SKUs) and the raw lines (receipts): no join between two derived
            // tables, so the planner cannot pick a nested loop over them.
            if (in_array(self::STORE, $what, true)) {
                DB::table('store_daily')->where('tenant_id', $tenantId)->whereBetween('date', [$from, $to])->delete();
                $n += DB::affectingStatement(
                    "INSERT INTO store_daily (tenant_id, store_id, date, receipts, lines, lines_with_receipt, units, revenue, skus_sold, created_at, updated_at)
                     SELECT ?, store_id, date, MAX(receipts), COALESCE(SUM(lines), 0), COALESCE(SUM(lwr), 0), SUM(units), SUM(revenue), SUM(skus), ?, ?
                       FROM (
                            SELECT store_id, date, NULL::bigint AS receipts, NULL::bigint AS lines, NULL::bigint AS lwr,
                                   SUM(units_sold) AS units, SUM(revenue) AS revenue, COUNT(*) FILTER (WHERE units_sold <> 0) AS skus
                              FROM sales_daily WHERE tenant_id = ? AND store_id IS NOT NULL AND date BETWEEN ? AND ?
                             GROUP BY store_id, date
                            UNION ALL
                            SELECT store_id, date,
                                   CASE WHEN COUNT(transaction_id) > 0 THEN COUNT(DISTINCT transaction_id) END,
                                   COUNT(*), COUNT(transaction_id), 0, 0, 0
                              FROM sales_transactions WHERE tenant_id = ? AND store_id IS NOT NULL AND date BETWEEN ? AND ?
                             GROUP BY store_id, date
                       ) u
                      GROUP BY store_id, date
                     HAVING SUM(units) <> 0 OR SUM(revenue) <> 0 OR SUM(skus) > 0",
                    [$tenantId, $now, $now, $tenantId, $from, $to, $tenantId, $from, $to]
                );
            }

            if (in_array(self::CATEGORY, $what, true)) {
                DB::table('category_daily')->where('tenant_id', $tenantId)->whereBetween('date', [$from, $to])->delete();
                $dept = "LEFT(COALESCE(TRIM(p.department), ''), 120)";
                $cat = "LEFT(COALESCE(TRIM(p.category), ''), 120)";
                $n += DB::affectingStatement(
                    "INSERT INTO category_daily (tenant_id, store_id, department, category, date, receipts, units, revenue, skus_sold, created_at, updated_at)
                     SELECT ?, store_id, department, category, date, MAX(receipts), SUM(units), SUM(revenue), SUM(skus), ?, ?
                       FROM (
                            SELECT sd.store_id, {$dept} AS department, {$cat} AS category, sd.date, NULL::bigint AS receipts,
                                   SUM(sd.units_sold) AS units, SUM(sd.revenue) AS revenue, COUNT(*) FILTER (WHERE sd.units_sold <> 0) AS skus
                              FROM sales_daily sd
                              LEFT JOIN products p ON p.tenant_id = sd.tenant_id AND p.sku = sd.sku
                             WHERE sd.tenant_id = ? AND sd.store_id IS NOT NULL AND sd.date BETWEEN ? AND ?
                             GROUP BY 1, 2, 3, 4
                            UNION ALL
                            SELECT st.store_id, {$dept}, {$cat}, st.date, COUNT(DISTINCT st.transaction_id), 0, 0, 0
                              FROM sales_transactions st
                              LEFT JOIN products p ON p.tenant_id = st.tenant_id AND p.sku = st.sku
                             WHERE st.tenant_id = ? AND st.store_id IS NOT NULL AND st.transaction_id IS NOT NULL AND st.date BETWEEN ? AND ?
                             GROUP BY 1, 2, 3, 4
                       ) u
                      GROUP BY store_id, department, category, date
                     HAVING SUM(units) <> 0 OR SUM(revenue) <> 0 OR SUM(skus) > 0",
                    [$tenantId, $now, $now, $tenantId, $from, $to, $tenantId, $from, $to]
                );
            }
        });

        return $n;
    }

    /** Rebuild the tenant's whole sales_daily history, a quarter at a time. @return int quarters */
    public function rebuildTenant(int $tenantId, array $what = [self::STORE, self::CATEGORY]): int
    {
        $range = DB::table('sales_daily')->where('tenant_id', $tenantId)->selectRaw('MIN(date) AS mn, MAX(date) AS mx')->first();
        if (! $range || ! $range->mn) {
            return 0;
        }
        $n = 0;
        for ($start = Carbon::parse($range->mn)->firstOfQuarter(); $start->lte(Carbon::parse($range->mx)); $start->addQuarter()) {
            $this->rebuild($tenantId, $start->toDateString(), $start->copy()->lastOfQuarter()->toDateString(), $what);
            $n++;
        }

        return $n;
    }
}
