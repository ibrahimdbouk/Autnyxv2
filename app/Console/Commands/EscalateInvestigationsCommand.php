<?php

namespace App\Console\Commands;

use App\Services\EscalationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class EscalateInvestigationsCommand extends Command
{
    protected $signature = 'investigations:escalate {--tenant= : Specific tenant ID}';

    protected $description = 'Evaluate escalation rules against all open investigations and fire escalations';

    public function handle(EscalationService $service): int
    {
        $tenantId = $this->option('tenant');

        try {
            if ($tenantId) {
                $this->info("Running escalation for tenant {$tenantId}…");
                $service->runForTenant((int) $tenantId);
            } else {
                $this->info('Running escalation for all tenants…');
                $service->runForAllTenants();
            }

            $this->info('Done.');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Failed: {$e->getMessage()}");
            Log::error('[investigations:escalate] ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return Command::FAILURE;
        }
    }
}
