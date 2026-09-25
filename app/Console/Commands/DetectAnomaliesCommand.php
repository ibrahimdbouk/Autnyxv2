<?php

namespace App\Console\Commands;

use App\Jobs\RunTenantDetectionJob;
use App\Models\Tenant;
use App\Services\Detection\TenantDetectionRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DetectAnomaliesCommand extends Command
{
    protected $signature = 'anomalies:detect
        {--tenant= : Specific tenant ID}
        {--mode= : full|incremental|aggregate (default: config detection.mode)}
        {--queue : Dispatch the run to the queue instead of running inline (needs a queue worker; avoids the sync command time limit on large tenants)}';

    protected $description = 'Run all anomaly detection rules for every tenant (or a specific one), then correlate into Investigations';

    public function handle(TenantDetectionRunner $runner): int
    {
        $mode     = $this->option('mode') ?: config('detection.mode', 'full');
        $tenantId = $this->option('tenant');
        $useQueue = (bool) $this->option('queue');

        // Async path: hand the run to a queue worker so it isn't bound by the
        // synchronous command time limit. The job resolves the mode itself
        // (null → config), so pass the raw option through. A worker must be
        // running for this to actually execute — see claude/async-detection.md.
        if ($useQueue) {
            $tenants = $tenantId ? [Tenant::findOrFail((int) $tenantId)] : Tenant::all()->all();
            foreach ($tenants as $tenant) {
                RunTenantDetectionJob::dispatch($tenant->id, $this->option('mode') ?: null);
            }
            $this->info('Queued detection for ' . count($tenants) . ' tenant(s). Requires a running queue worker.');

            return Command::SUCCESS;
        }

        if ($tenantId) {
            $this->info('Detecting anomalies for tenant ' . $tenantId . ' (' . $mode . ')…');
            try {
                $tenant = Tenant::findOrFail((int) $tenantId);
                $runner->run($tenant->id, $mode);
                $this->info('Done.');
            } catch (\App\Services\Pipeline\DetectionBusy $e) {
                $this->error($e->getMessage() . ' Try again when it finishes.');

                return Command::FAILURE;
            } catch (\Throwable $e) {
                $this->error("Failed: {$e->getMessage()}");
                Log::error('[anomalies:detect] ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);

                return Command::FAILURE;
            }

            return Command::SUCCESS;
        }

        $tenants = Tenant::all();

        if ($tenants->isEmpty()) {
            $this->warn('No tenants found.');

            return Command::SUCCESS;
        }

        $this->info('Running anomaly detection for ' . $tenants->count() . ' tenant(s) (' . $mode . ')…');
        $bar = $this->output->createProgressBar($tenants->count());
        $bar->start();

        $errors = 0;
        foreach ($tenants as $tenant) {
            try {
                $runner->run($tenant->id, $mode);
            } catch (\Throwable $e) {
                $errors++;
                Log::error("[anomalies:detect] Tenant {$tenant->id}: {$e->getMessage()}");
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        if ($errors > 0) {
            $this->warn("{$errors} tenant(s) failed — check logs.");

            return Command::FAILURE;
        }

        $this->info('All tenants processed successfully.');

        return Command::SUCCESS;
    }
}
