<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Sales\SalesRollupAggregator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Root Cause drivers — fill store_daily and category_daily from the daily
 * history (one-off backfill; kept current by every aggregation afterwards).
 * --derive-revenue first gives lines sent without a net amount their value
 * (quantity × unit price − discount) and rebuilds the daily aggregates.
 */
class SalesRollupsCommand extends Command
{
    protected $signature = 'sales:rollups {--tenant= : Only this tenant} {--derive-revenue : Value lines that have no net amount, then rebuild the aggregates} {--category-only}';

    protected $description = 'Rebuild store_daily and category_daily (and optionally derive missing line revenue)';

    public function handle(SalesRollupAggregator $agg): int
    {
        foreach (Tenant::query()->when($this->option('tenant'), fn ($q, $t) => $q->whereKey((int) $t))->orderBy('id')->get() as $t) {
            $start = microtime(true);
            if ($this->option('derive-revenue')) {
                $fixed = $this->deriveRevenue((int) $t->id);
                $this->line("  {$t->name}: {$fixed} line(s) valued from quantity × price.");
            }
            $what = $this->option('category-only') ? [SalesRollupAggregator::CATEGORY] : [SalesRollupAggregator::STORE, SalesRollupAggregator::CATEGORY];
            $q = $agg->rebuildTenant((int) $t->id, $what);
            $this->line("  {$t->name}: {$q} quarter(s) in " . round(microtime(true) - $start, 1) . 's — '
                . DB::table('store_daily')->where('tenant_id', $t->id)->count() . ' store days, '
                . DB::table('category_daily')->where('tenant_id', $t->id)->count() . ' category days, receipts on '
                . DB::table('store_daily')->where('tenant_id', $t->id)->whereNotNull('receipts')->count() . ' store days');
        }

        return self::SUCCESS;
    }

    /** Lines with no net amount get quantity × unit price − discount; the affected days are re-aggregated. */
    private function deriveRevenue(int $tenantId): int
    {
        $range = DB::table('sales_transactions')->where('tenant_id', $tenantId)->whereNull('total_amount')->whereNotNull('unit_price')
            ->selectRaw('MIN(date) AS mn, MAX(date) AS mx, COUNT(*) AS n')->first();
        if (! $range || (int) $range->n === 0) {
            return 0;
        }
        $fixed = 0;
        $last = 0;
        do {
            $ids = DB::table('sales_transactions')->where('tenant_id', $tenantId)->whereNull('total_amount')->whereNotNull('unit_price')
                ->where('id', '>', $last)->orderBy('id')->limit(20000)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $fixed += DB::table('sales_transactions')->whereIn('id', $ids->all())
                ->update(['total_amount' => DB::raw('ROUND(quantity * unit_price - COALESCE(discount, 0), 4)')]);
            $last = (int) $ids->last();
        } while (true);

        app(\App\Services\Sales\SalesDailyAggregator::class)->aggregateRange($tenantId, (string) $range->mn, (string) $range->mx);

        return $fixed;
    }
}
