<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\DataHealth\DataHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Feature 4 — recomputes Data Health snapshots for every tenant and fires
 * notifications for critical data conditions.
 */
class ComputeDataHealthCommand extends Command
{
    protected $signature = 'data:health {--tenant= : Specific tenant ID}';

    protected $description = 'Recompute Data Health snapshots and notify on critical data conditions';

    public function handle(DataHealthService $service): int
    {
        $tenantIds = $this->option('tenant')
            ? [(int) $this->option('tenant')]
            : Tenant::pluck('id')->all();

        foreach ($tenantIds as $tenantId) {
            try {
                $snapshots = $service->computeForTenant($tenantId);
                $service->notifyCritical($tenantId, $snapshots);
                $this->info("Tenant {$tenantId}: {$snapshots->count()} datasets scored.");
            } catch (\Throwable $e) {
                $this->error("Tenant {$tenantId} failed: {$e->getMessage()}");
                Log::error('[data:health] tenant ' . $tenantId . ': ' . $e->getMessage());
            }
        }

        return Command::SUCCESS;
    }
}
