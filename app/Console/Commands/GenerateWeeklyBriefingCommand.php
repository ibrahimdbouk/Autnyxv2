<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Agents\WeeklyBriefingAgent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Generates the Weekly Briefing (Agent #2) for every tenant, or one.
 * Scheduled Monday mornings; also runnable on demand.
 */
class GenerateWeeklyBriefingCommand extends Command
{
    /** Tenants that failed this run (WP5.2 — reported through the exit code). */
    private int $failures = 0;

    protected $signature = 'agents:weekly-briefing {--tenant= : Only this tenant ID}';

    protected $description = 'Generate the AI weekly business briefing for each tenant';

    public function handle(WeeklyBriefingAgent $agent): int
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
                Log::error('[agents:weekly-briefing] ' . $e->getMessage());
                $this->failures++; // WP5.2: a tenant that failed fails the command
            }
        }

        return $this->failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
