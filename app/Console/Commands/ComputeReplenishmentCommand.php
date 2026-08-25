<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Anomaly\ReplenishmentService;
use Illuminate\Console\Command;

class ComputeReplenishmentCommand extends Command
{
    protected $signature = 'replenishment:compute {--tenant= : Compute a single tenant}';

    protected $description = 'Derive per (store, SKU) reorder points, safety stock and suggested order quantities from the best-fit demand profile and observed lead times (B4)';

    public function handle(ReplenishmentService $service): int
    {
        $tenants = $this->option('tenant')
            ? Tenant::where('id', $this->option('tenant'))->get()
            : Tenant::all();

        foreach ($tenants as $tenant) {
            $count = $service->computeForTenant($tenant->id);
            $this->info("Tenant {$tenant->id}: {$count} replenishment targets computed");
        }

        return Command::SUCCESS;
    }
}
