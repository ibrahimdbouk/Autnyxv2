<?php

namespace App\Console\Commands;

use App\Models\DetectionDirtyKey;
use App\Models\Tenant;
use App\Services\Anomaly\AnomalyDetectionService;
use App\Services\Anomaly\InvestigationCorrelationService;
use App\Services\Detection\RunScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DetectAnomaliesCommand extends Command
{
    protected $signature = 'anomalies:detect
        {--tenant= : Specific tenant ID}
        {--mode= : full|incremental|aggregate (default: config detection.mode)}';

    protected $description = 'Run all anomaly detection rules for every tenant (or a specific one), then correlate into Investigations';

    public function handle(
        AnomalyDetectionService $detector,
        InvestigationCorrelationService $correlator
    ): int {
        $mode     = $this->option('mode') ?: config('detection.mode', 'full');
        $tenantId = $this->option('tenant');

        if ($tenantId) {
            $this->info('Detecting anomalies for tenant ' . $tenantId . ' (' . $mode . ')…');
            try {
                $tenant = Tenant::findOrFail((int) $tenantId);
                $this->runTenant($detector, $correlator, $tenant, $mode);
                $this->info('Done.');
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
                $this->runTenant($detector, $correlator, $tenant, $mode);
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

    /**
     * Run detection for one tenant in the chosen mode, then correlate.
     *
     *  full        — scan everything (unchanged); then clear the dirty queue so
     *                it can't grow unbounded while running in full mode.
     *  aggregate   — run only the rules the per-key incremental run skips, full
     *                scan; does not touch the queue (the incremental run owns it).
     *  incremental — scan only the changed + still-open SKUs; on success, consume
     *                the queue up to the id folded into the scope and advance the
     *                watermark. A too-broad change set falls back to a full scan.
     */
    private function runTenant(
        AnomalyDetectionService $detector,
        InvestigationCorrelationService $correlator,
        Tenant $tenant,
        string $mode
    ): void {
        if ($mode === 'aggregate') {
            $detector->runForTenant($tenant->id, null, true);
            $correlator->correlateForTenant($tenant->id);

            return;
        }

        if ($mode !== 'incremental') {
            // Full scan.
            $detector->runForTenant($tenant->id);
            $correlator->correlateForTenant($tenant->id);
            DetectionDirtyKey::where('tenant_id', $tenant->id)->delete();
            $this->stampWatermark($tenant->id);

            return;
        }

        $scope = RunScope::forTenant($tenant->id, (int) config('detection.max_union_skus', 20000));

        if ($scope === null) {
            // Change set too broad — a full scan is cheaper. Clear the whole queue.
            $detector->runForTenant($tenant->id);
            $correlator->correlateForTenant($tenant->id);
            DetectionDirtyKey::where('tenant_id', $tenant->id)->delete();
            $this->stampWatermark($tenant->id);

            return;
        }

        if ($scope->isEmpty()) {
            // Nothing changed and nothing open — skip the scan, just advance the watermark.
            $this->stampWatermark($tenant->id);

            return;
        }

        $detector->runForTenant($tenant->id, $scope);
        $correlator->correlateForTenant($tenant->id);

        // Consume only the keys folded into this run (concurrent inserts during
        // the run keep a higher id and survive for the next run).
        DetectionDirtyKey::where('tenant_id', $tenant->id)
            ->where('id', '<=', $scope->maxDirtyId())
            ->delete();
        $this->stampWatermark($tenant->id);
    }

    /** Query-builder update bypasses model events (mirrors the auth-listener pattern). */
    private function stampWatermark(int $tenantId): void
    {
        Tenant::whereKey($tenantId)->update(['last_detection_at' => now()]);
    }
}
