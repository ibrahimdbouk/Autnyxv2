<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * WP6.4 (audit H32) — row counts per tenant without scanning a big table.
 *
 * A table the planner estimates below $exactBelow rows is counted exactly
 * (cheap). A bigger one is estimated from PostgreSQL's own statistics: the
 * table's row estimate × each tenant's share of the tenant_id column
 * (pg_stats most-common values — with few tenants every tenant is one of
 * them). Good enough for usage dashboards; never used for billing or rules.
 */
final class TableStats
{
    /** @return array<int,int> tenant_id => rows */
    public static function rowsByTenant(string $table, int $exactBelow = 1_000_000): array
    {
        if (DB::getDriverName() === 'pgsql') {
            $est = DB::selectOne(
                "SELECT c.reltuples::bigint AS rows, s.most_common_vals::text AS vals, s.most_common_freqs AS freqs
                   FROM pg_class c
                   JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = current_schema()
                   LEFT JOIN pg_stats s ON s.schemaname = n.nspname AND s.tablename = c.relname AND s.attname = 'tenant_id'
                  WHERE c.relname = ?",
                [$table]
            );
            if ($est && (int) $est->rows >= $exactBelow && $est->vals !== null) {
                $vals  = array_map('intval', explode(',', trim((string) $est->vals, '{}')));
                $freqs = array_map('floatval', explode(',', trim((string) $est->freqs, '{}')));
                $out = [];
                foreach ($vals as $i => $tenantId) {
                    $out[$tenantId] = (int) round((int) $est->rows * ($freqs[$i] ?? 0));
                }

                return $out;
            }
        }

        return DB::table($table)->selectRaw('tenant_id, COUNT(*) AS c')->groupBy('tenant_id')
            ->pluck('c', 'tenant_id')->map(fn ($c) => (int) $c)->all();
    }

    public static function rowsForTenant(string $table, int $tenantId, int $exactBelow = 1_000_000): int
    {
        if (DB::getDriverName() === 'pgsql') {
            $rows = (int) (DB::selectOne('SELECT c.reltuples::bigint AS rows FROM pg_class c
                JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = current_schema() WHERE c.relname = ?', [$table])->rows ?? 0);
            if ($rows >= $exactBelow) {
                return (int) (self::rowsByTenant($table, $exactBelow)[$tenantId] ?? 0);
            }
        }

        return DB::table($table)->where('tenant_id', $tenantId)->count();
    }
}
