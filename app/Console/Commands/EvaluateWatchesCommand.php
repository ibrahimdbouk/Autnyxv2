<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Watch\WatchEvaluationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Feature 5 — evaluates active watches and notifies on meaningful changes.
 */
class EvaluateWatchesCommand extends Command
{
    protected $signature = 'investigations:evaluate-watches {--tenant= : Specific tenant ID}';

    protected $description = 'Evaluate investigation watches and dispatch notifications for meaningful changes';

    public function handle(WatchEvaluationService $service): int
    {
        $tenantIds = $this->option('tenant')
            ? [(int) $this->option('tenant')]
            : Tenant::pluck('id')->all();

        $total = 0;
        foreach ($tenantIds as $tenantId) {
            try {
                $sent = $service->evaluateTenant($tenantId);
                $total += $sent;
                $this->info("Tenant {$tenantId}: {$sent} watch notifications sent.");
            } catch (\Throwable $e) {
                $this->error("Tenant {$tenantId} failed: {$e->getMessage()}");
                Log::error('[evaluate-watches] tenant ' . $tenantId . ': ' . $e->getMessage());
            }
        }

        $this->info("Done. {$total} notifications dispatched.");
        return Command::SUCCESS;
    }
}
