<?php

namespace App\Services\Assortment;

use App\Models\AssortmentStoreRange;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Assortment A1 — which products each store carries, and since / until when.
 *
 * Customers rarely keep a clean range history, so it is rebuilt from what the
 * platform already holds: a product is CARRIED at a store if it had stock on
 * hand or a sale in the last `carried_window_days` (56) before the tenant's
 * latest data date; its first/last seen dates come from the first/last stock
 * or sale. Rows from a range/listing file (source 'listing') are never
 * overwritten by the inferred ones.
 *
 * The clock is the tenant's latest data date, not today — an import lag must
 * not make a whole range look dropped.
 */
class RangeModel
{
    /** The latest date the tenant has sales or stock data for, or null with no data. */
    public function asOfDate(int $tenantId): ?string
    {
        $r = DB::selectOne(
            'SELECT GREATEST(
                    (SELECT MAX(date) FROM sales_daily WHERE tenant_id = ? AND store_id IS NOT NULL),
                    (SELECT MAX(as_of_date) FROM inventory_levels WHERE tenant_id = ? AND store_id IS NOT NULL)
                ) AS d',
            [$tenantId, $tenantId],
        );

        return $r?->d ? Carbon::parse($r->d)->toDateString() : null;
    }

    /**
     * Rebuild the inferred range for a tenant as of a date.
     *
     * @return array{positions:int, carried:int, stores:int, skus:int, history_days:int, first_seen:?string}
     */
    public function rebuild(int $tenantId, string $asOf): array
    {
        $cutoff = Carbon::parse($asOf)->subDays(max(1, (int) config('assortment.carried_window_days', 56)) - 1)->toDateString();

        DB::transaction(function () use ($tenantId, $asOf, $cutoff) {
            DB::table('assortment_store_ranges')
                ->where('tenant_id', $tenantId)
                ->where('source', AssortmentStoreRange::SOURCE_INFERRED)
                ->delete();

            DB::statement(
                "INSERT INTO assortment_store_ranges
                    (tenant_id, store_id, sku, product_id, carried, source, first_seen, last_seen, last_sale, last_in_stock, as_of_date, created_at, updated_at)
                 SELECT ?, x.store_id, x.sku, p.id,
                        GREATEST(x.last_sale, x.last_in_stock) >= CAST(? AS date),
                        'inferred',
                        LEAST(x.first_sale, x.first_stock),
                        GREATEST(x.last_sale, x.last_in_stock),
                        x.last_sale, x.last_in_stock, CAST(? AS date), NOW(), NOW()
                   FROM (
                        SELECT COALESCE(s.store_id, i.store_id) AS store_id,
                               COALESCE(s.sku, i.sku)           AS sku,
                               s.first_sale, s.last_sale, i.first_stock, i.last_in_stock
                          FROM (SELECT store_id, sku, MIN(date) AS first_sale, MAX(date) AS last_sale
                                  FROM sales_daily
                                 WHERE tenant_id = ? AND store_id IS NOT NULL AND units_sold > 0 AND date <= CAST(? AS date)
                              GROUP BY store_id, sku) s
                     FULL OUTER JOIN
                               (SELECT store_id, sku,
                                       MIN(as_of_date) FILTER (WHERE on_hand_qty > 0) AS first_stock,
                                       MAX(as_of_date) FILTER (WHERE on_hand_qty > 0) AS last_in_stock
                                  FROM inventory_levels
                                 WHERE tenant_id = ? AND store_id IS NOT NULL AND as_of_date <= CAST(? AS date)
                              GROUP BY store_id, sku) i
                            ON i.store_id = s.store_id AND i.sku = s.sku
                   ) x
              LEFT JOIN products p ON p.tenant_id = ? AND p.sku = x.sku
                  WHERE LEAST(x.first_sale, x.first_stock) IS NOT NULL
             ON CONFLICT (tenant_id, store_id, sku) DO NOTHING",
                [$tenantId, $cutoff, $asOf, $tenantId, $asOf, $tenantId, $asOf, $tenantId],
            );
        });

        $s = DB::selectOne(
            'SELECT COUNT(*) AS positions,
                    SUM(CASE WHEN carried THEN 1 ELSE 0 END) AS carried,
                    COUNT(DISTINCT store_id) AS stores,
                    COUNT(DISTINCT sku) AS skus,
                    MIN(first_seen) AS first_seen
               FROM assortment_store_ranges WHERE tenant_id = ?',
            [$tenantId],
        );

        $first = $s->first_seen ? Carbon::parse($s->first_seen) : null;

        return [
            'positions'    => (int) $s->positions,
            'carried'      => (int) $s->carried,
            'stores'       => (int) $s->stores,
            'skus'         => (int) $s->skus,
            'history_days' => $first ? (int) $first->diffInDays(Carbon::parse($asOf)) + 1 : 0,
            'first_seen'   => $first?->toDateString(),
        ];
    }
}
