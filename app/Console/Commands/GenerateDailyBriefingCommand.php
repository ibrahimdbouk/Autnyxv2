<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Agents\DailyBriefingAgent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Generates the Daily Briefing for every tenant, or one.
 * Scheduled each morning; also runnable on demand.
 */
class GenerateDailyBriefingCommand extends Command
{
    protected $signature = 'agents:daily-briefing {--tenant= : Only this tenant ID}';

    protected $description = 'Generate the AI start-of-day briefing for each tenant';

    public function handle(DailyBriefingAgent $agent): int
    {
        $tenants = $this->option('tenant')
            ? Tenant::whereKey((int) $this->option('tenant'))->get()
            : Tenant::all();

        if ($tenants->isEmpty()) {
            $this->warn('No tenants found.');

            return self::SUCCESS;
        }

        foreach ($tenants as $tenant) {
            try {
                $run = $agent->generate($tenant->id);
                $this->info("Tenant {$tenant->id} ({$tenant->name}): briefing #{$run->id} — {$run->status}");
            } catch (\Throwable $e) {
                $this->error("Tenant {$tenant->id}: {$e->getMessage()}");
                Log::error('[agents:daily-briefing] ' . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
