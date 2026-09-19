<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Agents\DataQualityAgent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Runs the Data-Quality agent (Agent #3) for every tenant, or one.
 */
class RunDataQualityCommand extends Command
{
    protected $signature = 'agents:data-quality {--tenant= : Only this tenant ID}';

    protected $description = 'Run the AI data-quality / readiness check for each tenant';

    public function handle(DataQualityAgent $agent): int
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
                $run = $agent->checkTenant($tenant->id);
                $this->info("Tenant {$tenant->id} ({$tenant->name}): check #{$run->id} — {$run->status}");
            } catch (\Throwable $e) {
                $this->error("Tenant {$tenant->id}: {$e->getMessage()}");
                Log::error('[agents:data-quality] ' . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
