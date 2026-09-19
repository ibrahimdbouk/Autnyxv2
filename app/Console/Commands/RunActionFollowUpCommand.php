<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Agents\ActionFollowUpAgent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Runs the Action Follow-Up agent (Agent #6) across each tenant's actioned
 * investigations. Scheduled daily; also runnable on demand.
 */
class RunActionFollowUpCommand extends Command
{
    protected $signature = 'agents:action-followup {--tenant= : Only this tenant ID} {--limit=20 : Max investigations per tenant}';

    protected $description = 'Follow up on actioned investigations and draft "did it work?" notes';

    public function handle(ActionFollowUpAgent $agent): int
    {
        $tenants = $this->option('tenant')
            ? Tenant::whereKey((int) $this->option('tenant'))->get()
            : Tenant::all();

        if ($tenants->isEmpty()) {
            $this->warn('No tenants found.');

            return self::SUCCESS;
        }

        $limit = (int) $this->option('limit');

        foreach ($tenants as $tenant) {
            try {
                $runs = $agent->followForTenant($tenant->id, $limit > 0 ? $limit : 20);
                $this->info("Tenant {$tenant->id} ({$tenant->name}): " . count($runs) . ' follow-up(s) drafted.');
            } catch (\Throwable $e) {
                $this->error("Tenant {$tenant->id}: {$e->getMessage()}");
                Log::error('[agents:action-followup] ' . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
