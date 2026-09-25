<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\DataQuality\DataQualityChecks;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * W9 (WP9.3) — run the semantic / cross-dataset data checks. Part of each
 * tenant's nightly chain (health step) and queued after every import.
 */
class DataQualityCheckCommand extends Command
{
    protected $signature = 'dq:check {--tenant= : Specific tenant ID} {--quiet-alerts : Record findings without alerting}';

    protected $description = 'Run the data-quality checks (stores gone silent, partial days, price outliers, …)';

    public function handle(DataQualityChecks $checks): int
    {
        $ids = $this->option('tenant') ? [(int) $this->option('tenant')] : Tenant::where('status', 'active')->pluck('id')->all();
        $failed = 0;
        foreach ($ids as $id) {
            try {
                $r = $checks->run($id, alert: ! $this->option('quiet-alerts'));
                $this->info("Tenant {$id}: {$r['open']} open finding(s), {$r['opened']} new, {$r['resolved']} resolved.");
            } catch (\Throwable $e) {
                $failed++;
                $this->error("Tenant {$id} failed: {$e->getMessage()}");
                Log::error('[dq:check] tenant ' . $id . ': ' . $e->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
