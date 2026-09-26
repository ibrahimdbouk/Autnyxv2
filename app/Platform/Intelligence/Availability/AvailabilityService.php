<?php

namespace App\Platform\Intelligence\Availability;

use Illuminate\Support\Facades\DB;

/**
 * Platform intelligence: how often a product was on the shelf at a store.
 *
 * Read by every app from the platform's own inventory history
 * (inventory_levels), so no app has to import another app's findings — the
 * Assortment app, for instance, may not read Root Cause's stockout anomalies,
 * but it needs to know a product "sells slowly" only because it is usually out.
 *
 * Availability = the share of stock observations (one per store × SKU × day,
 * summing lots and locations) that had stock on hand. With daily snapshots it
 * is the share of days in stock; with sparser snapshots it is the same figure
 * at a coarser grain. A position with no observation in the window has no
 * availability (null) — never 100% by assumption.
 */
class AvailabilityService
{
    /**
     * Availability of every observed (store, SKU) in a window, optionally for
     * some stores only.
     *
     * @param  array<int,int>|null  $storeIds
     * @return array<string,array{store_id:int,sku:string,observations:int,in_stock:int,availability:float,first_observed:string,last_observed:string,last_in_stock:?string}>
     *         keyed "storeId|sku"
     */
    public function forWindow(int $tenantId, string $from, string $to, ?array $storeIds = null): array
    {
        $params = [$tenantId, $from, $to];
        $storeFilter = '';
        if ($storeIds !== null) {
            if ($storeIds === []) {
                return [];
            }
            $storeFilter = ' AND store_id IN (' . implode(',', array_fill(0, count($storeIds), '?')) . ')';
            array_push($params, ...array_map('intval', $storeIds));
        }

        $rows = DB::select(
            "SELECT store_id, sku,
                    COUNT(*)                                        AS observations,
                    SUM(CASE WHEN qty > 0 THEN 1 ELSE 0 END)        AS in_stock,
                    MIN(d)                                          AS first_observed,
                    MAX(d)                                          AS last_observed,
                    MAX(CASE WHEN qty > 0 THEN d END)               AS last_in_stock
               FROM (
                    SELECT store_id, sku, as_of_date AS d, SUM(on_hand_qty) AS qty
                      FROM inventory_levels
                     WHERE tenant_id = ? AND store_id IS NOT NULL
                       AND as_of_date BETWEEN ? AND ?{$storeFilter}
                  GROUP BY store_id, sku, as_of_date
               ) daily
           GROUP BY store_id, sku",
            $params,
        );

        $out = [];
        foreach ($rows as $r) {
            $obs = (int) $r->observations;
            $out[$r->store_id . '|' . $r->sku] = [
                'store_id'       => (int) $r->store_id,
                'sku'            => (string) $r->sku,
                'observations'   => $obs,
                'in_stock'       => (int) $r->in_stock,
                'availability'   => $obs > 0 ? round(((int) $r->in_stock) / $obs, 4) : 0.0,
                'first_observed' => (string) $r->first_observed,
                'last_observed'  => (string) $r->last_observed,
                'last_in_stock'  => $r->last_in_stock !== null ? (string) $r->last_in_stock : null,
            ];
        }

        return $out;
    }

    /** Availability of one (store, SKU) in a window, or null when never observed. */
    public function forPosition(int $tenantId, int $storeId, string $sku, string $from, string $to): ?float
    {
        $all = $this->forWindow($tenantId, $from, $to, [$storeId]);

        return $all[$storeId . '|' . $sku]['availability'] ?? null;
    }

    /**
     * How the tenant's stock history looks: how many distinct snapshot days
     * and the span covered — so a consumer can say how much to trust the figure.
     *
     * @return array{days:int, first:?string, last:?string}
     */
    public function coverage(int $tenantId): array
    {
        $r = DB::selectOne(
            'SELECT COUNT(DISTINCT as_of_date) AS days, MIN(as_of_date) AS first, MAX(as_of_date) AS last
               FROM inventory_levels WHERE tenant_id = ? AND store_id IS NOT NULL',
            [$tenantId],
        );

        return [
            'days'  => (int) ($r->days ?? 0),
            'first' => $r->first ?? null,
            'last'  => $r->last ?? null,
        ];
    }
}
