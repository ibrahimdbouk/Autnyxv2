<?php

namespace App\Console\Commands;

use App\Models\Anomaly;
use App\Models\Tenant;
use App\Services\Anomaly\AnomalyDetectionService;
use App\Services\Detection\RunScope;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Validate incremental scoping before switching DETECTION_MODE=incremental.
 *
 * Runs a full and an incremental detection pass for one tenant, each inside a
 * rolled-back transaction (no persistent writes), and compares the anomalies
 * each produced for the incremental allowlist rules on the changed SKUs. Parity
 * means incremental is safe to turn on. See claude/incremental-detection-design.md.
 */
class ShadowDiffCommand extends Command
{
    protected $signature = 'detection:shadow-diff {--tenant= : Tenant ID (required)}';

    protected $description = 'Compare a full vs incremental detection run for one tenant (rolled back — no writes) to validate incremental scoping.';

    public function handle(AnomalyDetectionService $detector): int
    {
        $tenantId = (int) $this->option('tenant');
        if (! $tenantId || ! Tenant::whereKey($tenantId)->exists()) {
            $this->error('Pass a valid --tenant=<id>.');

            return Command::FAILURE;
        }

        $cap   = (int) config('detection.max_union_skus', 20000);
        $scope = RunScope::forTenant($tenantId, $cap);

        if ($scope === null) {
            $this->warn('Change set exceeds the cap — incremental would fall back to full here. Nothing to diff.');

            return Command::SUCCESS;
        }
        if ($scope->isEmpty()) {
            $this->warn('Nothing changed and nothing open — no scope to diff. Import some data first.');

            return Command::SUCCESS;
        }

        $skus  = $scope->skus();
        $rules = AnomalyDetectionService::INCREMENTAL_RULES;

        $full = $this->captureRun($tenantId, $rules, $skus, fn () => $detector->runForTenant($tenantId));
        $incr = $this->captureRun($tenantId, $rules, $skus, fn () => $detector->runForTenant($tenantId, $scope));

        $missing = $full->diff($incr)->values(); // full found, incremental didn't — the danger
        $extra   = $incr->diff($full)->values(); // incremental found, full didn't — unexpected

        $this->newLine();
        $this->info("Shadow diff for tenant {$tenantId} — allowlisted rules on " . count($skus) . ' scoped SKU(s):');
        $this->line('  full-run anomalies:        ' . $full->count());
        $this->line('  incremental-run anomalies: ' . $incr->count());
        $this->line('  matching:                  ' . $full->intersect($incr)->count());

        if ($missing->isEmpty() && $extra->isEmpty()) {
            $this->info('  PARITY — incremental matches full for the allowlisted rules on the changed SKUs.');

            return Command::SUCCESS;
        }

        if ($missing->isNotEmpty()) {
            $this->error('  MISSING in incremental (' . $missing->count() . '): ' . $missing->take(15)->implode(', '));
        }
        if ($extra->isNotEmpty()) {
            $this->warn('  EXTRA in incremental (' . $extra->count() . '): ' . $extra->take(15)->implode(', '));
        }

        return Command::FAILURE;
    }

    /**
     * Run detection inside a rolled-back transaction and return the active anomaly
     * identity_keys for the given rules on the given SKUs. No persistent writes.
     *
     * @param  array<int,string>  $rules
     * @param  array<int,string>  $skus
     * @return Collection<int,string>
     */
    private function captureRun(int $tenantId, array $rules, array $skus, \Closure $run): Collection
    {
        DB::beginTransaction();
        try {
            $run();

            return Anomaly::where('tenant_id', $tenantId)
                ->whereIn('rule_type', $rules)
                ->whereIn('sku', $skus)
                ->whereIn('lifecycle_state', [
                    Anomaly::LIFECYCLE_OPEN,
                    Anomaly::LIFECYCLE_PERSISTING,
                    Anomaly::LIFECYCLE_CLEARING,
                ])
                ->pluck('identity_key')
                ->unique()
                ->values();
        } finally {
            DB::rollBack();
        }
    }
}
