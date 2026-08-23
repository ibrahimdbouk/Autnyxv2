<?php

namespace App\Services\Sales;

use App\Models\Import;
use App\Models\SalesTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Maintains the sales_daily aggregate incrementally from raw sales_transactions.
 *
 * The aggregation runs entirely inside PostgreSQL (INSERT … SELECT … GROUP BY …
 * ON CONFLICT DO UPDATE), so it is memory-safe regardless of table size and is
 * idempotent — re-running for the same date range recomputes those days' totals
 * from scratch rather than double-counting. Only the affected date range is
 * touched, so cost scales with new data, not total history.
 */
class SalesDailyAggregator
{
    /**
     * Rebuild sales_daily for a tenant over [$from, $to] (inclusive) from the
     * current raw transactions. Returns the number of daily rows written.
     */
    public function aggregateRange(int $tenantId, string $from, string $to): int
    {
        $now = now()->toDateTimeString();

        // Portable upsert (works on PostgreSQL and the SQLite test DB). Null
        // store_id rows are excluded — cannibalization is store-level and a null
        // store cannot be attributed, and a nullable column breaks the ON CONFLICT
        // dedup target anyway.
        DB::statement(
            'INSERT INTO sales_daily
                (tenant_id, store_id, sku, date, units_sold, revenue, transaction_count, created_at, updated_at)
             SELECT tenant_id, store_id, sku, date,
                    SUM(quantity)                AS units_sold,
                    SUM(COALESCE(total_amount, 0)) AS revenue,
                    COUNT(*)                     AS transaction_count,
                    ?, ?
             FROM sales_transactions
             WHERE tenant_id = ?
               AND store_id IS NOT NULL
               AND date BETWEEN ? AND ?
             GROUP BY tenant_id, store_id, sku, date
             ON CONFLICT (tenant_id, store_id, sku, date)
             DO UPDATE SET
                 units_sold        = EXCLUDED.units_sold,
                 revenue           = EXCLUDED.revenue,
                 transaction_count = EXCLUDED.transaction_count,
                 updated_at        = EXCLUDED.updated_at',
            [$now, $now, $tenantId, $from, $to]
        );

        return (int) DB::table('sales_daily')
            ->where('tenant_id', $tenantId)
            ->whereBetween('date', [$from, $to])
            ->count();
    }

    /**
     * Aggregate just the date range covered by a completed sales import, so the
     * daily layer stays current without ever rebuilding all history.
     */
    public function aggregateForImport(Import $import): int
    {
        if ($import->data_type !== Import::TYPE_SALES) {
            return 0;
        }

        $range = SalesTransaction::where('import_id', $import->id)
            ->selectRaw('MIN(date) as mn, MAX(date) as mx')
            ->first();

        if (! $range || ! $range->mn) {
            return 0;
        }

        $from = $range->mn instanceof \DateTimeInterface ? $range->mn->format('Y-m-d') : (string) $range->mn;
        $to   = $range->mx instanceof \DateTimeInterface ? $range->mx->format('Y-m-d') : (string) $range->mx;

        return $this->aggregateRange($import->tenant_id, $from, $to);
    }

    /**
     * Full rebuild for a tenant (e.g. a one-off backfill). Clears the tenant's
     * rows then re-aggregates the entire history in one grouped pass.
     */
    public function rebuildForTenant(int $tenantId): int
    {
        DB::table('sales_daily')->where('tenant_id', $tenantId)->delete();

        $range = SalesTransaction::where('tenant_id', $tenantId)
            ->selectRaw('MIN(date) as mn, MAX(date) as mx')
            ->first();

        if (! $range || ! $range->mn) {
            return 0;
        }

        $from = $range->mn instanceof \DateTimeInterface ? $range->mn->format('Y-m-d') : (string) $range->mn;
        $to   = $range->mx instanceof \DateTimeInterface ? $range->mx->format('Y-m-d') : (string) $range->mx;

        return $this->aggregateRange($tenantId, $from, $to);
    }
}
