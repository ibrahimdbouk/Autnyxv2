<?php

namespace App\Services\Inventory;

use App\Models\Import;
use Illuminate\Support\Facades\DB;

/**
 * WP6.2 (audit H20) — keeps `inventory_current` equal to "the newest snapshot
 * of each (store, SKU) position in inventory_levels, lots summed", plus the
 * snapshot before it (for shrink) and since when its reorder point has held.
 *
 * Recomputed from the history, never incremented, so any refresh is
 * idempotent and a late, older snapshot can never overwrite a newer position.
 * Each position costs a few index seeks on (tenant, store, sku, as_of_date),
 * whatever the length of its history (WP6.3: an inventory load never re-reads
 * the whole history).
 *
 *  - refreshForImport(): after an inventory import (or retry / promote) — the
 *    positions that import touched.
 *  - refreshKeys(): given positions (rollback, Eloquent writes).
 *  - rebuild(): a whole tenant (backfill, repair), including the reorder-point
 *    history.
 *
 * A position whose history is gone (rolled back, purged) leaves the table.
 */
class InventoryCurrentService
{
    /** The positions an import wrote to. */
    public function refreshForImport(Import $import): int
    {
        if ($import->data_type !== Import::TYPE_INVENTORY) {
            return 0;
        }

        return $this->refresh(
            (int) $import->tenant_id,
            'SELECT DISTINCT store_id, sku FROM inventory_levels WHERE tenant_id = ? AND import_id = ? AND store_id IS NOT NULL',
            [(int) $import->tenant_id, (int) $import->id]
        );
    }

    /** @param  array<int,array{store_id:int|null,sku:string|null}>  $keys */
    public function refreshKeys(int $tenantId, array $keys): int
    {
        $keys = array_values(array_filter($keys, fn ($k) => ! empty($k['store_id']) && ($k['sku'] ?? '') !== ''));
        if ($keys === []) {
            return 0;
        }

        $written = 0;
        foreach (array_chunk($keys, 1000) as $chunk) {
            $values = implode(', ', array_fill(0, count($chunk), '(?::bigint, ?::text)'));
            $binds  = [];
            foreach ($chunk as $k) {
                $binds[] = (int) $k['store_id'];
                $binds[] = (string) $k['sku'];
            }
            $written += $this->refresh($tenantId, "SELECT * FROM (VALUES {$values}) AS v(store_id, sku)", $binds);
        }

        return $written;
    }

    /**
     * Build a tenant's positions if it has inventory history but none yet
     * (first run after WP6.2 shipped, or a tenant restored from backup).
     */
    public function ensure(int $tenantId): void
    {
        $hasCurrent = DB::table('inventory_current')->where('tenant_id', $tenantId)->exists();
        if (! $hasCurrent && DB::table('inventory_levels')->where('tenant_id', $tenantId)->whereNotNull('store_id')->exists()) {
            $this->rebuild($tenantId);
        }
    }

    /** Recompute every position of a tenant, reorder-point history included. */
    public function rebuild(int $tenantId): int
    {
        $n = DB::transaction(function () use ($tenantId) {
            $stamp = now()->subSecond();
            $n = $this->upsert($tenantId,
                'SELECT DISTINCT store_id, sku FROM inventory_levels WHERE tenant_id = ? AND store_id IS NOT NULL',
                [$tenantId]);
            DB::table('inventory_current')->where('tenant_id', $tenantId)->where('updated_at', '<', $stamp)->delete();
            $this->rebuildReorderPointSince($tenantId);

            return $n;
        });
        // W13: a whole tenant rewritten — fresh planner statistics for detection
        // (cheap next to the rebuild; stale ones made evidence lookups 20× slower).
        \App\Services\Anomaly\AnomalyDetectionService::refreshStats(['inventory_current']);

        return $n;
    }

    /** Positions a keys subquery names: upsert the newest snapshot, drop the ones with no history left. */
    private function refresh(int $tenantId, string $keysSql, array $keysBind): int
    {
        return DB::transaction(function () use ($tenantId, $keysSql, $keysBind) {
            $n = $this->upsert($tenantId, $keysSql, $keysBind);

            DB::delete(
                "WITH k AS ({$keysSql})
                 DELETE FROM inventory_current c USING k
                  WHERE c.tenant_id = ? AND c.store_id = k.store_id AND c.sku = k.sku
                    AND NOT EXISTS (SELECT 1 FROM inventory_levels l
                                     WHERE l.tenant_id = c.tenant_id AND l.store_id = c.store_id AND l.sku = c.sku
                                       AND l.as_of_date IS NOT NULL)",
                array_merge($keysBind, [$tenantId])
            );

            return $n;
        });
    }

    private function upsert(int $tenantId, string $keysSql, array $keysBind): int
    {
        $now = now()->toDateTimeString();

        return DB::affectingStatement(
            "WITH k AS ({$keysSql}),
             d AS (
                SELECT k.store_id, k.sku,
                       (SELECT MAX(l.as_of_date) FROM inventory_levels l
                         WHERE l.tenant_id = ? AND l.store_id = k.store_id AND l.sku = k.sku) AS d
                  FROM k
             ),
             dp AS (
                SELECT d.store_id, d.sku, d.d,
                       (SELECT MAX(l.as_of_date) FROM inventory_levels l
                         WHERE l.tenant_id = ? AND l.store_id = d.store_id AND l.sku = d.sku AND l.as_of_date < d.d) AS pd
                  FROM d WHERE d.d IS NOT NULL
             ),
             cur AS (
                SELECT dp.store_id, dp.sku, dp.d AS as_of_date, dp.pd,
                       SUM(COALESCE(l.on_hand_qty, 0))   AS on_hand_qty,
                       SUM(l.on_order_qty)               AS on_order_qty,
                       SUM(l.allocated_qty)              AS allocated_qty,
                       SUM(l.in_transit_qty)             AS in_transit_qty,
                       SUM(l.inventory_value)            AS inventory_value,
                       MAX(l.reorder_point)              AS reorder_point,
                       MAX(l.safety_stock)               AS safety_stock,
                       COALESCE(SUM(l.unit_cost * l.on_hand_qty) FILTER (WHERE l.unit_cost IS NOT NULL AND l.on_hand_qty > 0)
                                / NULLIF(SUM(l.on_hand_qty) FILTER (WHERE l.unit_cost IS NOT NULL AND l.on_hand_qty > 0), 0),
                                MAX(l.unit_cost))        AS unit_cost,
                       MIN(l.expiry_date)                AS earliest_expiry,
                       MAX(l.product_id)                 AS product_id,
                       MAX(l.location)                   AS location,
                       COUNT(*)                          AS lots,
                       MAX(l.import_id)                  AS import_id
                  FROM dp
                  JOIN inventory_levels l ON l.tenant_id = ? AND l.store_id = dp.store_id AND l.sku = dp.sku AND l.as_of_date = dp.d
                 GROUP BY dp.store_id, dp.sku, dp.d, dp.pd
             ),
             prv AS (
                SELECT dp.store_id, dp.sku, SUM(COALESCE(l.on_hand_qty, 0)) AS qty, MAX(l.reorder_point) AS rp
                  FROM dp
                  JOIN inventory_levels l ON l.tenant_id = ? AND l.store_id = dp.store_id AND l.sku = dp.sku AND l.as_of_date = dp.pd
                 GROUP BY dp.store_id, dp.sku
             )
             INSERT INTO inventory_current
                (tenant_id, store_id, sku, product_id, location, as_of_date, on_hand_qty, on_order_qty, allocated_qty,
                 in_transit_qty, inventory_value, reorder_point, safety_stock, unit_cost, earliest_expiry, lots,
                 prev_as_of_date, prev_on_hand_qty, reorder_point_since, import_id, created_at, updated_at)
             SELECT ?, cur.store_id, cur.sku, cur.product_id, cur.location, cur.as_of_date, cur.on_hand_qty, cur.on_order_qty,
                    cur.allocated_qty, cur.in_transit_qty, cur.inventory_value, cur.reorder_point, cur.safety_stock,
                    cur.unit_cost, cur.earliest_expiry, cur.lots, cur.pd, prv.qty,
                    -- a new position: the run starts at the earlier snapshot when it had the same reorder point
                    CASE WHEN prv.rp IS NOT DISTINCT FROM cur.reorder_point AND cur.pd IS NOT NULL THEN cur.pd ELSE cur.as_of_date END,
                    cur.import_id, ?, ?
               FROM cur LEFT JOIN prv ON prv.store_id = cur.store_id AND prv.sku = cur.sku
             ON CONFLICT (tenant_id, store_id, sku) DO UPDATE SET
                product_id = EXCLUDED.product_id, location = EXCLUDED.location, as_of_date = EXCLUDED.as_of_date,
                on_hand_qty = EXCLUDED.on_hand_qty, on_order_qty = EXCLUDED.on_order_qty,
                allocated_qty = EXCLUDED.allocated_qty, in_transit_qty = EXCLUDED.in_transit_qty,
                inventory_value = EXCLUDED.inventory_value, reorder_point = EXCLUDED.reorder_point,
                safety_stock = EXCLUDED.safety_stock, unit_cost = EXCLUDED.unit_cost,
                earliest_expiry = EXCLUDED.earliest_expiry, lots = EXCLUDED.lots,
                prev_as_of_date = EXCLUDED.prev_as_of_date, prev_on_hand_qty = EXCLUDED.prev_on_hand_qty,
                -- an unchanged reorder point keeps its start; a changed one starts now
                reorder_point_since = CASE
                    WHEN inventory_current.reorder_point IS NOT DISTINCT FROM EXCLUDED.reorder_point
                         AND inventory_current.reorder_point_since IS NOT NULL
                    THEN LEAST(inventory_current.reorder_point_since, EXCLUDED.as_of_date)
                    ELSE EXCLUDED.reorder_point_since END,
                import_id = EXCLUDED.import_id, updated_at = EXCLUDED.updated_at",
            array_merge($keysBind, [$tenantId, $tenantId, $tenantId, $tenantId, $tenantId, $now, $now])
        );
    }

    /**
     * Since when each position's reorder point has held: the latest snapshot
     * where it differed from the snapshot before (one pass over the history —
     * rebuilds only; imports keep it up to date incrementally).
     */
    private function rebuildReorderPointSince(int $tenantId): void
    {
        DB::update(
            "WITH s AS (
                SELECT store_id, sku, as_of_date, MAX(reorder_point) AS rp
                  FROM inventory_levels
                 WHERE tenant_id = ? AND store_id IS NOT NULL AND as_of_date IS NOT NULL
                 GROUP BY store_id, sku, as_of_date
             ), m AS (
                SELECT s.*, rp IS DISTINCT FROM LAG(rp) OVER (PARTITION BY store_id, sku ORDER BY as_of_date) AS changed FROM s
             ), since AS (
                SELECT DISTINCT ON (store_id, sku) store_id, sku, as_of_date AS since
                  FROM m WHERE changed ORDER BY store_id, sku, as_of_date DESC
             )
             UPDATE inventory_current c SET reorder_point_since = since.since
               FROM since WHERE c.tenant_id = ? AND c.store_id = since.store_id AND c.sku = since.sku",
            [$tenantId, $tenantId]
        );
    }
}
