<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WP6.1 (audit H30, migrations M-data) — the one way to add or drop an index
 * on a table that is written while the app runs.
 *
 *  - CREATE / DROP INDEX CONCURRENTLY: no write lock on the table. It cannot
 *    run inside a transaction, so the migration sets `$withinTransaction = false`
 *    (inside a test transaction the plain form is used; it is equivalent there).
 *  - A short lock_timeout: the build waits for running transactions; if one
 *    holds on, the attempt gives up instead of queueing every writer behind it,
 *    and is retried.
 *  - An index left INVALID by an interrupted build is dropped and rebuilt.
 *  - Idempotent: a valid index with the same name is left alone, so a migration
 *    that half-ran can simply be run again.
 *
 * See docs/migrations.md for the convention and stubs/migration.concurrent-index.stub.
 */
final class ConcurrentIndex
{
    /**
     * @param  string       $columns  the column list, e.g. "(tenant_id, sku, date)" or "USING gin (sku gin_trgm_ops)"
     * @param  string|null  $where    partial-index predicate
     * @return string created | exists | skipped (not PostgreSQL)
     */
    public static function create(string $name, string $table, string $columns, ?string $where = null, bool $unique = false, int $attempts = 3): string
    {
        if (DB::getDriverName() !== 'pgsql') {
            return 'skipped';
        }

        $state = self::state($name);
        if ($state === 'valid') {
            return 'exists';
        }

        $concurrently = self::concurrently();
        $sql = 'CREATE ' . ($unique ? 'UNIQUE ' : '') . "INDEX {$concurrently}IF NOT EXISTS {$name} ON {$table} "
            . $columns
            . ($where ? " WHERE {$where}" : '');

        for ($try = 1; ; $try++) {
            try {
                if (self::state($name) === 'invalid') {
                    self::dropNow($name);
                }
                self::withLockTimeout(fn () => DB::statement($sql));

                if (self::state($name) !== 'valid') {
                    throw new \RuntimeException("{$name} was built but is not valid");
                }

                return 'created';
            } catch (\Throwable $e) {
                Log::warning('[index] build attempt failed', ['index' => $name, 'attempt' => $try, 'error' => $e->getMessage()]);
                if ($try >= $attempts || DB::transactionLevel() > 0) {
                    throw $e;
                }
                sleep(min(30, 5 * $try));
            }
        }
    }

    public static function drop(string $name): void
    {
        if (DB::getDriverName() !== 'pgsql' || self::state($name) === null) {
            return;
        }
        self::withLockTimeout(fn () => self::dropNow($name));
    }

    /** valid | invalid | null (absent) */
    public static function state(string $name): ?string
    {
        $row = DB::selectOne(
            "select i.indisvalid as valid from pg_class c join pg_index i on i.indexrelid = c.oid
              join pg_namespace n on n.oid = c.relnamespace where c.relname = ? and n.nspname = current_schema()",
            [$name]
        );

        return $row === null ? null : ($row->valid ? 'valid' : 'invalid');
    }

    /** Enable an extension if the database allows it; false (logged) when it does not. */
    public static function extension(string $name): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            return false;
        }
        try {
            DB::statement("CREATE EXTENSION IF NOT EXISTS {$name}");

            return true;
        } catch (\Throwable $e) {
            Log::warning("[index] extension {$name} unavailable", ['error' => $e->getMessage()]);

            return false;
        }
    }

    public static function hasExtension(string $name): bool
    {
        return DB::getDriverName() === 'pgsql'
            && DB::selectOne('select 1 as ok from pg_extension where extname = ?', [$name]) !== null;
    }

    private static function dropNow(string $name): void
    {
        DB::statement('DROP INDEX ' . self::concurrently() . "IF EXISTS {$name}");
    }

    private static function concurrently(): string
    {
        return DB::transactionLevel() === 0 ? 'CONCURRENTLY ' : '';
    }

    private static function withLockTimeout(callable $fn): mixed
    {
        $timeout = (string) config('database.migration_lock_timeout', '10s');
        if (DB::transactionLevel() > 0) {
            DB::statement("SET LOCAL lock_timeout = '{$timeout}'");

            return $fn();
        }
        $before = [DB::selectOne('SHOW lock_timeout')->lock_timeout, DB::selectOne('SHOW statement_timeout')->statement_timeout];
        DB::statement("SET lock_timeout = '{$timeout}'");
        DB::statement('SET statement_timeout = 0');
        try {
            return $fn();
        } finally {
            DB::statement("SET lock_timeout = '{$before[0]}'");
            DB::statement("SET statement_timeout = '{$before[1]}'");
        }
    }
}
