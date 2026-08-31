<?php

namespace App\Console\Commands;

use App\Platform\Intelligence\Clustering\ClusterService;
use Illuminate\Console\Command;

/**
 * Rebuild store clusters (Platform\Intelligence\Clustering). Runs nightly for
 * every tenant; --tenant restricts to one, --method overrides the strategy.
 */
class RebuildClustersCommand extends Command
{
    protected $signature = 'clusters:rebuild {--tenant= : Only this tenant id} {--method= : Override the configured strategy}';

    protected $description = 'Rebuild store clusters for tenants (attribute strategy by default).';

    public function handle(ClusterService $service): int
    {
        $method = $this->option('method') ?: null;

        if ($tenant = $this->option('tenant')) {
            $count = $service->rebuild((int) $tenant, $method);
            $this->info("Rebuilt {$count} clusters for tenant {$tenant}.");

            return self::SUCCESS;
        }

        $results = $service->rebuildAll($method);
        $this->info('Rebuilt clusters for ' . count($results) . ' tenant(s), ' . array_sum($results) . ' clusters total.');

        return self::SUCCESS;
    }
}
