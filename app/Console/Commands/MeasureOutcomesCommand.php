<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Outcome\OutcomeMeasurementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Feature 8 — deterministically measures post-action outcomes for every tenant.
 */
class MeasureOutcomesCommand extends Command
{
    /** Tenants that failed this run (WP5.2 — reported through the exit code). */
    private int $failures = 0;

    protected $signature = 'outcomes:measure {--tenant= : Specific tenant ID}';

    protected $description = 'Measure post-action business outcomes (deterministic) for resolved/actioned investigations';

    public function handle(OutcomeMeasurementService $service): int
    {
        $tenantIds = $this->option('tenant')
            ? [(int) $this->option('tenant')]
            : Tenant::pluck('id')->all();

        $total = 0;
        foreach ($tenantIds as $tenantId) {
            try {
                $count = $service->measureTenant($tenantId);
                $total += $count;
                $this->info("Tenant {$tenantId}: {$count} investigations measured.");
            } catch (\Throwable $e) {
                $this->error("Tenant {$tenantId} failed: {$e->getMessage()}");
                Log::error('[outcomes:measure] tenant ' . $tenantId . ': ' . $e->getMessage());
                $this->failures++; // WP5.2: a tenant that failed fails the command
            }
        }

        $this->info("Done. {$total} measurements.");
        return $this->failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
