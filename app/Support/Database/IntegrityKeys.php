<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WP6.5 (audit M25, H7) — the lookup keys and tenant foreign keys the schema
 * was missing. Shared by the migration and `db:integrity`:
 *
 *  - unique keys are built CONCURRENTLY only on a duplicate-free table; a table
 *    with duplicates is skipped (with a warning) until `db:integrity --apply`
 *    (derived tables) or a merge (suppliers / stores) has removed them;
 *  - tenant foreign keys are added NOT VALID (new rows are checked at once)
 *    and VALIDATEd when no row points at a tenant that no longer exists.
 */
final class IntegrityKeys
{
    /** name => [table, key expression, predicate, partition columns for the duplicate check] */
    public const UNIQUE = [
        'suppliers_tenant_name_ci_unique' => ['suppliers', '(tenant_id, lower(trim(name)))', null, 'tenant_id, lower(trim(name))'],
        'stores_tenant_code_ci_unique'    => ['stores', '(tenant_id, lower(trim(code)))', "code IS NOT NULL AND trim(code) <> ''", 'tenant_id, lower(trim(code))'],
        'sku_baselines_key_nnd'           => ['sku_baselines', '(tenant_id, sku, store_id, rule_type, metric) NULLS NOT DISTINCT', null, 'tenant_id, sku, store_id, rule_type, metric'],
    ];

    /** Superseded once its NULLS NOT DISTINCT replacement exists. */
    public const REPLACES = ['sku_baselines_key_nnd' => 'sku_baselines_unique'];

    /** Derived tables whose duplicates may simply be deleted (recomputed nightly). */
    public const DERIVED = ['sku_baselines'];

    /** Tables that had a tenant_id without a foreign key. */
    public const TENANT_FK = ['sku_baselines', 'sku_profiles', 'agent_runs', 'campaign_reviews'];

    public static function duplicates(string $name): int
    {
        [$table, , $where, $partition] = self::UNIQUE[$name];

        return (int) DB::selectOne(
            "SELECT COALESCE(SUM(n - 1), 0) AS c FROM (SELECT COUNT(*) AS n FROM {$table}"
            . ($where ? " WHERE {$where}" : '') . " GROUP BY {$partition} HAVING COUNT(*) > 1) d"
        )->c;
    }

    /** Delete the older duplicates of a derived table's key. */
    public static function dedupe(string $name): int
    {
        [$table, , $where, $partition] = self::UNIQUE[$name];
        if (! in_array($table, self::DERIVED, true)) {
            throw new \LogicException("{$table} is not derived — merge its duplicates, don't delete them.");
        }

        return DB::affectingStatement(
            "DELETE FROM {$table} WHERE id IN (SELECT id FROM (SELECT id, row_number() OVER (PARTITION BY {$partition} ORDER BY id DESC) AS rn
               FROM {$table}" . ($where ? " WHERE {$where}" : '') . ') d WHERE d.rn > 1)'
        );
    }

    public static function orphans(string $table): int
    {
        return (int) DB::selectOne(
            "SELECT COUNT(*) AS c FROM {$table} t WHERE t.tenant_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM tenants x WHERE x.id = t.tenant_id)"
        )->c;
    }

    /** @return array<string,string> key => created | exists | validated | not valid yet: … | skipped: … */
    public static function ensure(): array
    {
        if (DB::getDriverName() !== 'pgsql') {
            return [];
        }
        $out = [];

        foreach (self::UNIQUE as $name => [$table, $cols, $where]) {
            if (ConcurrentIndex::state($name) === 'valid') {
                $out[$name] = 'exists';
            } elseif (($d = self::duplicates($name)) > 0) {
                $out[$name] = "skipped: {$d} duplicate row(s) — run `php artisan db:integrity`";
                Log::warning("[WP6.5] {$name} not created", ['duplicates' => $d]);
                continue;
            } else {
                $out[$name] = ConcurrentIndex::create($name, $table, $cols, $where, unique: true);
            }
            if (isset(self::REPLACES[$name])) {
                $old = self::REPLACES[$name];
                if (DB::selectOne('SELECT 1 AS x FROM pg_constraint WHERE conname = ?', [$old])) {
                    DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$old}");   // a unique constraint owns its index
                } else {
                    ConcurrentIndex::drop($old);
                }
            }
        }

        foreach (self::TENANT_FK as $table) {
            $fk = "{$table}_tenant_id_fk";
            $state = DB::selectOne('SELECT convalidated AS v FROM pg_constraint WHERE conname = ?', [$fk]);
            if ($state === null) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$fk} FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE NOT VALID");
                $state = (object) ['v' => false];
            }
            if ($state->v) {
                $out[$fk] = 'exists';
                continue;
            }
            if (($o = self::orphans($table)) > 0) {
                $out[$fk] = "not valid yet: {$o} row(s) of deleted tenants — run `php artisan db:integrity --apply`";
                Log::warning("[WP6.5] {$fk} left NOT VALID", ['orphans' => $o]);
                continue;
            }
            DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$fk}");
            $out[$fk] = 'validated';
        }

        return $out;
    }
}
