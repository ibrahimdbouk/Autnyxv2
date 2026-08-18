<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Anomaly\BaselineCalculatorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ComputeBaselinesCommand extends Command
{
    protected $signature = 'baselines:compute {--tenant= : Specific tenant ID to compute baselines for}';

    protected $description = 'Compute adaptive SKU baselines (mean, stddev) for anomaly z-score thresholding';

    public function handle(BaselineCalculatorService $service): int
    {
        $tenantId = $this->option('tenant');

        if ($tenantId) {
            $this->info("Computing baselines for tenant {$tenantId}…");
            try {
                $service->computeForTenant((int) $tenantId);
                $this->info('Done.');
            } catch (\Throwable $e) {
                $this->error("Failed: {$e->getMessage()}");
                Log::error('[baselines:compute] ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
                return Command::FAILURE;
            }
            return Command::SUCCESS;
        }

        $tenants = Tenant::all();

        if ($tenants->isEmpty()) {
            $this->warn('No tenants found.');
            return Command::SUCCESS;
        }

        $this->info("Computing baselines for {$tenants->count()} tenant(s)…");
        $bar = $this->output->createProgressBar($tenants->count());
        $bar->start();

        $errors = 0;
        foreach ($tenants as $tenant) {
            try {
                $service->computeForTenant($tenant->id);
            } catch (\Throwable $e) {
                $errors++;
                Log::error("[baselines:compute] Tenant {$tenant->id}: {$e->getMessage()}");
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        if ($errors > 0) {
            $this->warn("{$errors} tenant(s) failed — check logs.");
        } else {
            $this->info('All baselines computed successfully.');
        }

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
