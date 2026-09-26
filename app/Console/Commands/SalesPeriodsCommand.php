<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Sales\SalesPeriodAggregator;
use Illuminate\Console\Command;

/** W13 — build the weekly and monthly sales tables from the daily history (one-off backfill; kept current afterwards). */
class SalesPeriodsCommand extends Command
{
    protected $signature = 'sales:periods {--tenant= : Only this tenant}';

    protected $description = 'Rebuild sales_weekly and sales_monthly from sales_daily';

    public function handle(SalesPeriodAggregator $agg): int
    {
        foreach (Tenant::query()->when($this->option('tenant'), fn ($q, $t) => $q->whereKey((int) $t))->get() as $t) {
            $start = microtime(true);
            $chunks = $agg->rebuildTenant($t->id);
            $this->line("  {$t->name}: {$chunks} quarter(s) in " . round(microtime(true) - $start, 1) . 's — '
                . \Illuminate\Support\Facades\DB::table('sales_weekly')->where('tenant_id', $t->id)->count() . ' weekly rows, '
                . \Illuminate\Support\Facades\DB::table('sales_monthly')->where('tenant_id', $t->id)->count() . ' monthly rows');
        }

        return self::SUCCESS;
    }
}
