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

        // WP6.6 (audit M24): days older than the raw-sales retention window are
        // final — their receipt lines are purged, so rebuilding them from raw
        // would erase them. Never rebuild before the raw cutoff.
        $rawFloor = self::rawFloor();
        if ($rawFloor !== null && $from < $rawFloor) {
            $from = $rawFloor;
        }
        if ($from > $to) {
            return 0;
        }

        // WP3.5 (audit H27): the range is REBUILT, not merely upserted — a
        // (store, SKU, day) whose raw rows were rolled back must disappear
        // instead of lingering as phantom demand.
        $n = DB::transaction(function () use ($tenantId, $from, $to, $now) {
            DB::table('sales_daily')->where('tenant_id', $tenantId)->whereBetween('date', [$from, $to])->delete();

            return $this->insertRange($tenantId, $from, $to, $now);
        });

        // W13: the weeks and months those days belong to.
        app(SalesPeriodAggregator::class)->rebuild($tenantId, $from, $to);

        return $n;
    }

    private function insertRange(int $tenantId, string $from, string $to, string $now): int
    {
        $fx = app(\App\Services\Fx\FxService::class);
        if ($fx->needsConversion($tenantId, $from, $to)) {
            return $this->insertRangeConverted($tenantId, $from, $to, $now, $fx->base($tenantId));
        }

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
                    -- WP3.1: rows are receipt LINES; count receipts (lines without a receipt id count alone).
                    COUNT(DISTINCT transaction_id) + COUNT(*) FILTER (WHERE transaction_id IS NULL) AS transaction_count,
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
     * W13: as insertRange, with each line's revenue converted into the tenant's
     * currency (the line's currency, else its store's) at the rate in force
     * that day. A currency with no rate is left unconverted (the data check
     * fx_rate_missing reports it).
     */
    private function insertRangeConverted(int $tenantId, string $from, string $to, string $now, string $base): int
    {
        DB::statement(
            "INSERT INTO sales_daily
                (tenant_id, store_id, sku, date, units_sold, revenue, transaction_count, created_at, updated_at)
             SELECT st.tenant_id, st.store_id, st.sku, st.date,
                    SUM(st.quantity),
                    SUM(COALESCE(st.total_amount, 0) * COALESCE(r.rate, 1)),
                    COUNT(DISTINCT st.transaction_id) + COUNT(*) FILTER (WHERE st.transaction_id IS NULL),
                    ?, ?
               FROM sales_transactions st
               LEFT JOIN stores s ON s.id = st.store_id
               LEFT JOIN LATERAL (
                    SELECT f.rate FROM fx_rates f
                     WHERE f.tenant_id = st.tenant_id
                       AND f.currency = upper(COALESCE(NULLIF(st.currency, ''), s.currency))
                       AND f.valid_from <= st.date
                     ORDER BY f.valid_from DESC LIMIT 1
               ) r ON upper(COALESCE(NULLIF(st.currency, ''), s.currency, ?)) <> ?
              WHERE st.tenant_id = ? AND st.store_id IS NOT NULL AND st.date BETWEEN ? AND ?
              GROUP BY st.tenant_id, st.store_id, st.sku, st.date
             ON CONFLICT (tenant_id, store_id, sku, date)
             DO UPDATE SET units_sold = EXCLUDED.units_sold, revenue = EXCLUDED.revenue,
                           transaction_count = EXCLUDED.transaction_count, updated_at = EXCLUDED.updated_at",
            [$now, $now, $base, $base, $tenantId, $from, $to]
        );

        return (int) DB::table('sales_daily')->where('tenant_id', $tenantId)->whereBetween('date', [$from, $to])->count();
    }

    /** The first day whose raw sales lines are still retained (null: no raw retention). */
    public static function rawFloor(): ?string
    {
        $days = (int) (config('retention.tables.sales_transactions.days') ?? 0);

        return $days > 0 ? now()->subDays($days)->toDateString() : null;
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
        // WP6.6: only the days the raw lines still cover are rebuilt (aggregateRange
        // replaces exactly that range); older aggregates outlive their purged lines.
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
