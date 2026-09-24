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
