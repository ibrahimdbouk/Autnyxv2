<?php

namespace App\Services\Sales;

use Illuminate\Support\Facades\DB;

/**
 * W13 — weekly (ISO, Monday start) and monthly store × SKU sales, rebuilt from
 * sales_daily for exactly the periods a daily rebuild touched. Reports,
 * year-on-year views and Assortment read these instead of re-adding days.
 */
class SalesPeriodAggregator
{
    /** Rebuild every week and month that overlaps [$from, $to] (inclusive). */
    public function rebuild(int $tenantId, string $from, string $to): void
    {
        if ($from > $to) {
            return;
        }
        $now = now()->toDateTimeString();
        foreach (['sales_weekly' => ['week_start', 'week', "interval '7 days'"], 'sales_monthly' => ['month_start', 'month', "interval '1 month'"]] as $table => [$col, $unit, $span]) {
            DB::transaction(function () use ($table, $col, $unit, $span, $tenantId, $from, $to, $now) {
                DB::statement(
                    "DELETE FROM {$table} WHERE tenant_id = ? AND {$col} >= date_trunc('{$unit}', ?::date)::date AND {$col} <= date_trunc('{$unit}', ?::date)::date",
                    [$tenantId, $from, $to]
                );
                DB::statement(
                    "INSERT INTO {$table} (tenant_id, store_id, sku, {$col}, units_sold, revenue, transaction_count, days_sold, created_at, updated_at)
                     SELECT tenant_id, store_id, sku, date_trunc('{$unit}', date)::date,
                            SUM(units_sold), SUM(revenue), SUM(transaction_count), COUNT(*) FILTER (WHERE units_sold > 0), ?, ?
                       FROM sales_daily
                      WHERE tenant_id = ? AND store_id IS NOT NULL
                        AND date >= date_trunc('{$unit}', ?::date)::date
                        AND date < (date_trunc('{$unit}', ?::date) + {$span})::date
                      GROUP BY tenant_id, store_id, sku, date_trunc('{$unit}', date)",
                    [$now, $now, $tenantId, $from, $to]
                );
            });
        }
    }

    /** Backfill a tenant's whole daily history, a quarter at a time (bounded transactions). */
    public function rebuildTenant(int $tenantId): int
    {
        $r = DB::selectOne('SELECT MIN(date)::text AS a, MAX(date)::text AS b FROM sales_daily WHERE tenant_id = ?', [$tenantId]);
        if (! $r?->a) {
            return 0;
        }
        $cursor = \Illuminate\Support\Carbon::parse($r->a)->startOfMonth();
        $end = \Illuminate\Support\Carbon::parse($r->b);
        $chunks = 0;
        while ($cursor->lte($end)) {
            $chunkEnd = $cursor->copy()->addMonths(3)->subDay();
            $this->rebuild($tenantId, $cursor->toDateString(), min($chunkEnd, $end)->toDateString());
            $cursor = $chunkEnd->addDay();
            $chunks++;
        }

        return $chunks;
    }
}
