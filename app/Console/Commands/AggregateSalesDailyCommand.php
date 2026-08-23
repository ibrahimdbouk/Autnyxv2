<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Sales\SalesDailyAggregator;
use Illuminate\Console\Command;

class AggregateSalesDailyCommand extends Command
{
    protected $signature = 'sales:aggregate {--tenant= : Rebuild a single tenant}';

    protected $description = 'Rebuild the sales_daily aggregate from raw sales transactions';

    public function handle(SalesDailyAggregator $aggregator): int
    {
        $tenants = $this->option('tenant')
            ? Tenant::where('id', $this->option('tenant'))->get()
            : Tenant::all();

        foreach ($tenants as $tenant) {
            $rows = $aggregator->rebuildForTenant($tenant->id);
            $this->info("Tenant {$tenant->id}: {$rows} daily rows.");
        }

        return Command::SUCCESS;
    }
}
