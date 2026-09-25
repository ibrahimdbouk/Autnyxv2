<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WP3.5 (audit H27) — the natural-key unique indexes of the fact tables.
 * Shared by the migration and `imports:dedupe-natural-keys`: a table that
 * still holds duplicates is skipped (with a warning) until the command has
 * removed them, then the command creates the index. Built CONCURRENTLY.
 */
final class NaturalKeyIndexes
{
    /** name => [table, partition columns, index columns, predicate] */
    public const INDEXES = [
        'inventory_levels_natural_key' => ['inventory_levels', ['tenant_id', 'store_id', 'sku', 'as_of_date', 'batch_ref'], '(tenant_id, store_id, sku, as_of_date, batch_ref) NULLS NOT DISTINCT', null],
        'purchase_orders_natural_key'  => ['purchase_orders', ['tenant_id', 'po_number', 'sku', 'store_id'], '(tenant_id, po_number, sku, store_id) NULLS NOT DISTINCT', null],
        'sales_returns_natural_key'    => ['sales_returns', ['tenant_id', 'return_id', 'sku'], '(tenant_id, return_id, sku)', 'return_id IS NOT NULL'],
    ];

    /** SQL selecting the ids of rows that repeat a key (all but the newest). */
    public static function duplicateIdsSql(string $table, ?int $tenantId = null): string
    {
        foreach (self::INDEXES as [$t, $cols, , $where]) {
            if ($t !== $table) {
                continue;
            }
            $filters = array_filter([$where, $tenantId !== null ? 'tenant_id = ' . $tenantId : null]);
            $whereSql = $filters ? 'WHERE ' . implode(' AND ', $filters) : '';

            return 'SELECT id FROM (SELECT id, row_number() OVER (PARTITION BY ' . implode(', ', $cols) . " ORDER BY id DESC) AS rn FROM {$table} {$whereSql}) d WHERE d.rn > 1";
        }

        throw new \InvalidArgumentException("No natural key for {$table}");
    }

    /**
     * SQL for the ids of EVERY row in a duplicate group (keepers included) —
     * what the repair backs up before touching anything.
     */
    public static function groupIdsSql(string $table): string
    {
        foreach (self::INDEXES as [$t, $cols, , $where]) {
            if ($t === $table) {
                $whereSql = $where ? "WHERE {$where}" : '';

                return 'SELECT id FROM (SELECT id, count(*) OVER (PARTITION BY ' . implode(', ', $cols) . ") AS n FROM {$table} {$whereSql}) d WHERE d.n > 1";
            }
        }

        throw new \InvalidArgumentException("No natural key for {$table}");
    }

    /**
     * Inventory: rows repeating (store, sku, date, lot) inside a load are parts
     * of one position (bins / lots without a lot id). Merge them into the newest
     * row — quantities summed, reorder point / safety stock the maximum — so no
     * stock disappears. Returns rows merged away (the caller deletes them).
     */
    public static function mergeInventorySql(): string
    {
        return <<<'SQL'
            WITH g AS (
                SELECT max(id) AS keep,
                       sum(on_hand_qty) AS oh, sum(on_order_qty) AS oo, sum(inventory_value) AS iv,
                       sum(allocated_qty) AS aq, sum(in_transit_qty) AS it,
                       max(reorder_point) AS rp, max(safety_stock) AS ss
                FROM inventory_levels
                GROUP BY tenant_id, store_id, sku, as_of_date, batch_ref
                HAVING count(*) > 1
            )
            UPDATE inventory_levels t
               SET on_hand_qty = COALESCE(g.oh, 0), on_order_qty = g.oo, inventory_value = g.iv,
                   allocated_qty = g.aq, in_transit_qty = g.it, reorder_point = g.rp, safety_stock = g.ss,
                   updated_at = now()
              FROM g
             WHERE t.id = g.keep
            SQL;
    }

    public static function duplicates(string $table, ?int $tenantId = null): int
    {
        return (int) DB::selectOne('SELECT count(*) AS c FROM (' . self::duplicateIdsSql($table, $tenantId) . ') x')->c;
    }

    /**
     * Create every missing index whose table is duplicate-free.
     *
     * @return array<string,string> index => created | exists | skipped: N duplicates
     */
    public static function ensure(): array
    {
        if (DB::getDriverName() !== 'pgsql') {
            return [];
        }

        // CONCURRENTLY is impossible inside a transaction (e.g. a test); the plain form is equivalent there.
        $concurrently = DB::transactionLevel() === 0 ? 'CONCURRENTLY ' : '';

        $result = [];
        foreach (self::INDEXES as $name => [$table, , $cols, $where]) {
            $state = DB::selectOne('select i.indisvalid as valid from pg_class c join pg_index i on i.indexrelid = c.oid where c.relname = ?', [$name]);
            if ($state && $state->valid) {
                $result[$name] = 'exists';
                continue;
            }
            if ($state) {
                DB::statement("DROP INDEX {$concurrently}IF EXISTS {$name}");
            }

            $dupes = self::duplicates($table);
            if ($dupes > 0) {
                $result[$name] = "skipped: {$dupes} duplicate row(s) — run `php artisan imports:dedupe-natural-keys --apply`";
                Log::warning("[WP3.5] {$name} not created", ['table' => $table, 'duplicates' => $dupes]);
                continue;
            }

            DB::statement("CREATE UNIQUE INDEX {$concurrently}IF NOT EXISTS {$name} ON {$table} {$cols}" . ($where ? " WHERE {$where}" : ''));
            $result[$name] = 'created';
        }

        return $result;
    }

    public static function drop(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
        }
    }
}
