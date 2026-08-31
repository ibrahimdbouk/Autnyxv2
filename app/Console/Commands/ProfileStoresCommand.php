<?php

namespace App\Console\Commands;

use App\Platform\Intelligence\StoreProfiler;
use Illuminate\Console\Command;

/**
 * Recompute the store feature layer (Platform\Intelligence). Runs nightly for
 * every tenant; --tenant restricts to one, --window overrides the trailing days.
 */
class ProfileStoresCommand extends Command
{
    protected $signature = 'stores:profile {--tenant= : Only this tenant id} {--window=90 : Trailing window in days}';

    protected $description = 'Recompute per-store behavioural features from sales, products and sku_profiles.';

    public function handle(StoreProfiler $profiler): int
    {
        $window = (int) $this->option('window') ?: 90;

        if ($tenant = $this->option('tenant')) {
            $n = $profiler->rebuild((int) $tenant, $window);
            $this->info("Profiled {$n} stores for tenant {$tenant}.");

            return self::SUCCESS;
        }

        $results = $profiler->rebuildAll($window);
        $this->info('Profiled ' . array_sum($results) . ' stores across ' . count($results) . ' tenant(s).');

        return self::SUCCESS;
    }
}
