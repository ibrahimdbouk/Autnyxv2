<?php

namespace App\Console\Commands;

use App\Platform\Intelligence\Clustering\Strategies\AttributeClustering;
use App\Platform\Intelligence\Clustering\Strategies\DemandClustering;
use Illuminate\Console\Command;

/**
 * Compare structural (attribute) vs behavioural (demand) clustering for a tenant,
 * WITHOUT persisting either — strategies are pure. Shows how the behavioural pass
 * regroups stores the structural pass lumped together, so demand clustering can be
 * validated before CLUSTERING_STRATEGY is flipped. Mirrors detection:shadow-diff.
 */
class ClusterShadowDiffCommand extends Command
{
    protected $signature = 'clusters:shadow-diff {--tenant= : Tenant id (required)}';

    protected $description = 'Compare attribute vs demand clustering for a tenant (no writes).';

    public function handle(AttributeClustering $attribute, DemandClustering $demand): int
    {
        $tenantId = (int) $this->option('tenant');
        if ($tenantId <= 0) {
            $this->error('Pass --tenant=<id>.');

            return self::INVALID;
        }

        $attrGroups = $attribute->cluster($tenantId);
        $demandGroups = $demand->cluster($tenantId);

        if ($demandGroups === []) {
            $this->warn('No behavioural clusters — has StoreProfiler run for this tenant? (store_features empty)');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Attribute: %d cluster(s). Demand: %d cluster(s).',
            count($attrGroups),
            count($demandGroups),
        ));

        // store_id -> label, for each grouping.
        $demandLabel = [];
        foreach ($demandGroups as $g) {
            foreach ($g['store_ids'] as $id) {
                $demandLabel[$id] = $g['label'];
            }
        }

        // For each structural cluster, show how its stores split behaviourally.
        $rows = [];
        foreach ($attrGroups as $g) {
            $split = [];
            foreach ($g['store_ids'] as $id) {
                $label = $demandLabel[$id] ?? '(unprofiled)';
                $split[$label] = ($split[$label] ?? 0) + 1;
            }
            arsort($split);
            $parts = [];
            foreach ($split as $label => $count) {
                $parts[] = "{$count}× {$label}";
            }
            $rows[] = [
                $g['label'],
                count($g['store_ids']),
                count($split) > 1 ? 'SPLIT' : 'intact',
                implode('; ', $parts),
            ];
        }

        $this->table(
            ['Attribute cluster', 'Stores', 'Behaviourally', 'Demand breakdown'],
            $rows,
        );

        $splits = array_filter($rows, fn ($r) => $r[2] === 'SPLIT');
        $this->info(sprintf(
            '%d of %d structural clusters are split by behaviour — that regrouping is what demand clustering adds.',
            count($splits),
            count($rows),
        ));

        return self::SUCCESS;
    }
}
