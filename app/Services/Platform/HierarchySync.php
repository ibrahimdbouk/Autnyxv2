<?php

namespace App\Services\Platform;

use Illuminate\Support\Facades\DB;

/**
 * WP6.5 (audit M25) — keeps the canonical hierarchies (product, location,
 * supplier nodes) in step with the master tables. They were backfilled once
 * and never maintained, so every product, store or supplier created after
 * 2026-09-05 was missing from them.
 *
 * Set-based and idempotent: per tenant it adds the missing interior nodes
 * (category / subcategory, region, supplier group), adds a leaf for every
 * master row without one, refreshes each leaf's name, code, parent and
 * attributes, and removes leaves whose master row is gone. Runs after a
 * master-data import, when a master row is saved one at a time (queued once
 * per tenant per request), and nightly (`hierarchy:sync`).
 */
class HierarchySync
{
    /** @var array<int,true> tenants with a pending sync this request */
    private static array $pending = [];

    /** Queue a sync for the end of this request / command (once per tenant). */
    public static function later(?int $tenantId): void
    {
        if (! $tenantId || isset(self::$pending[$tenantId])) {
            return;
        }
        if (self::$pending === []) {
            app()->terminating(function () {
                foreach (array_keys(self::$pending) as $id) {
                    try {
                        app(self::class)->syncTenant($id);
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
                self::$pending = [];
            });
        }
        self::$pending[$tenantId] = true;
    }

    /** @return array{products:int,locations:int,suppliers:int} leaves written */
    public function syncTenant(int $tenantId): array
    {
        return DB::transaction(fn () => [
            'products'  => $this->products($tenantId),
            'locations' => $this->locations($tenantId),
            'suppliers' => $this->suppliers($tenantId),
        ]);
    }

    private function products(int $t): int
    {
        $now = now()->toDateTimeString();

        DB::insert(
            "INSERT INTO product_nodes (tenant_id, type, name, created_at, updated_at)
             SELECT DISTINCT ?::bigint, 'category', p.category, ?::timestamp, ?::timestamp
               FROM products p
              WHERE p.tenant_id = ? AND p.category IS NOT NULL AND p.category <> ''
                AND NOT EXISTS (SELECT 1 FROM product_nodes n WHERE n.tenant_id = ? AND n.type = 'category' AND n.name = p.category)",
            [$t, $now, $now, $t, $t]
        );
        DB::insert(
            "INSERT INTO product_nodes (tenant_id, type, name, parent_id, created_at, updated_at)
             SELECT DISTINCT ?::bigint, 'subcategory', p.subcategory, c.id, ?::timestamp, ?::timestamp
               FROM products p
               JOIN product_nodes c ON c.tenant_id = ? AND c.type = 'category' AND c.name = p.category
              WHERE p.tenant_id = ? AND p.subcategory IS NOT NULL AND p.subcategory <> ''
                AND NOT EXISTS (SELECT 1 FROM product_nodes n WHERE n.tenant_id = ? AND n.type = 'subcategory'
                                   AND n.name = p.subcategory AND n.parent_id = c.id)",
            [$t, $now, $now, $t, $t, $t]
        );

        $parent = "COALESCE(
                (SELECT s.id FROM product_nodes s JOIN product_nodes c ON c.id = s.parent_id
                  WHERE s.tenant_id = p.tenant_id AND s.type = 'subcategory' AND s.name = p.subcategory
                    AND c.type = 'category' AND c.name = p.category ORDER BY s.id LIMIT 1),
                (SELECT c.id FROM product_nodes c WHERE c.tenant_id = p.tenant_id AND c.type = 'category' AND c.name = p.category ORDER BY c.id LIMIT 1))";
        $attrs = "NULLIF(jsonb_strip_nulls(jsonb_build_object('brand', NULLIF(p.brand, ''), 'pack_size', NULLIF(p.pack_size, ''))), '{}'::jsonb)::json";

        $n = DB::affectingStatement(
            "INSERT INTO product_nodes (tenant_id, type, code, name, parent_id, product_id, attributes, created_at, updated_at)
             SELECT p.tenant_id, 'product', p.sku, p.name, {$parent}, p.id, {$attrs}, ?::timestamp, ?::timestamp
               FROM products p
              WHERE p.tenant_id = ?
                AND NOT EXISTS (SELECT 1 FROM product_nodes n WHERE n.tenant_id = p.tenant_id AND n.type = 'product' AND n.product_id = p.id)",
            [$now, $now, $t]
        );
        $n += DB::affectingStatement(
            "UPDATE product_nodes n SET code = p.sku, name = p.name, parent_id = {$parent}, attributes = {$attrs}, updated_at = ?::timestamp
               FROM products p
              WHERE n.tenant_id = ? AND n.type = 'product' AND n.product_id = p.id
                AND (n.code IS DISTINCT FROM p.sku OR n.name IS DISTINCT FROM p.name OR n.parent_id IS DISTINCT FROM {$parent}
                     OR n.attributes::jsonb IS DISTINCT FROM {$attrs}::jsonb)",
            [$now, $t]
        );
        DB::delete("DELETE FROM product_nodes WHERE tenant_id = ? AND type = 'product' AND product_id IS NULL", [$t]);

        return $n;
    }

    private function locations(int $t): int
    {
        $now = now()->toDateTimeString();

        DB::insert(
            "INSERT INTO location_nodes (tenant_id, type, name, created_at, updated_at)
             SELECT DISTINCT ?::bigint, 'region', s.region, ?::timestamp, ?::timestamp
               FROM stores s
              WHERE s.tenant_id = ? AND s.region IS NOT NULL AND s.region <> ''
                AND NOT EXISTS (SELECT 1 FROM location_nodes n WHERE n.tenant_id = ? AND n.type = 'region' AND n.name = s.region)",
            [$t, $now, $now, $t, $t]
        );

        $parent = "(SELECT r.id FROM location_nodes r WHERE r.tenant_id = s.tenant_id AND r.type = 'region' AND r.name = s.region ORDER BY r.id LIMIT 1)";
        $attrs = "NULLIF(jsonb_strip_nulls(jsonb_build_object('city', NULLIF(s.city, ''), 'country', NULLIF(s.country, ''), 'format', NULLIF(s.format, ''))), '{}'::jsonb)::json";

        $n = DB::affectingStatement(
            "INSERT INTO location_nodes (tenant_id, type, code, name, parent_id, store_id, attributes, created_at, updated_at)
             SELECT s.tenant_id, 'store', s.code, s.name, {$parent}, s.id, {$attrs}, ?::timestamp, ?::timestamp
               FROM stores s
              WHERE s.tenant_id = ?
                AND NOT EXISTS (SELECT 1 FROM location_nodes n WHERE n.tenant_id = s.tenant_id AND n.type = 'store' AND n.store_id = s.id)",
            [$now, $now, $t]
        );
        $n += DB::affectingStatement(
            "UPDATE location_nodes n SET code = s.code, name = s.name, parent_id = {$parent}, attributes = {$attrs}, updated_at = ?::timestamp
               FROM stores s
              WHERE n.tenant_id = ? AND n.type = 'store' AND n.store_id = s.id
                AND (n.code IS DISTINCT FROM s.code OR n.name IS DISTINCT FROM s.name OR n.parent_id IS DISTINCT FROM {$parent}
                     OR n.attributes::jsonb IS DISTINCT FROM {$attrs}::jsonb)",
            [$now, $t]
        );
        DB::delete("DELETE FROM location_nodes WHERE tenant_id = ? AND type = 'store' AND store_id IS NULL", [$t]);

        return $n;
    }

    private function suppliers(int $t): int
    {
        $now = now()->toDateTimeString();

        DB::insert(
            "INSERT INTO supplier_nodes (tenant_id, type, name, created_at, updated_at)
             SELECT DISTINCT ?::bigint, 'group', s.type, ?::timestamp, ?::timestamp
               FROM suppliers s
              WHERE s.tenant_id = ? AND s.type IS NOT NULL AND s.type <> ''
                AND NOT EXISTS (SELECT 1 FROM supplier_nodes n WHERE n.tenant_id = ? AND n.type = 'group' AND n.name = s.type)",
            [$t, $now, $now, $t, $t]
        );

        $parent = "(SELECT g.id FROM supplier_nodes g WHERE g.tenant_id = s.tenant_id AND g.type = 'group' AND g.name = s.type ORDER BY g.id LIMIT 1)";
        $attrs = "NULLIF(jsonb_strip_nulls(jsonb_build_object('specialization', NULLIF(s.specialization, ''), 'lead_time_days', s.lead_time_days)), '{}'::jsonb)::json";

        $n = DB::affectingStatement(
            "INSERT INTO supplier_nodes (tenant_id, type, code, name, parent_id, supplier_id, attributes, created_at, updated_at)
             SELECT s.tenant_id, 'supplier', s.code, s.name, {$parent}, s.id, {$attrs}, ?::timestamp, ?::timestamp
               FROM suppliers s
              WHERE s.tenant_id = ?
                AND NOT EXISTS (SELECT 1 FROM supplier_nodes n WHERE n.tenant_id = s.tenant_id AND n.type = 'supplier' AND n.supplier_id = s.id)",
            [$now, $now, $t]
        );
        $n += DB::affectingStatement(
            "UPDATE supplier_nodes n SET code = s.code, name = s.name, parent_id = {$parent}, attributes = {$attrs}, updated_at = ?::timestamp
               FROM suppliers s
              WHERE n.tenant_id = ? AND n.type = 'supplier' AND n.supplier_id = s.id
                AND (n.code IS DISTINCT FROM s.code OR n.name IS DISTINCT FROM s.name OR n.parent_id IS DISTINCT FROM {$parent}
                     OR n.attributes::jsonb IS DISTINCT FROM {$attrs}::jsonb)",
            [$now, $t]
        );
        DB::delete("DELETE FROM supplier_nodes WHERE tenant_id = ? AND type = 'supplier' AND supplier_id IS NULL", [$t]);

        return $n;
    }
}
