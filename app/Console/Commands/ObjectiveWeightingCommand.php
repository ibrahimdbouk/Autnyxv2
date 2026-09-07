<?php

namespace App\Console\Commands;

use App\Platform\Objectives\WeightingRegistry;
use Illuminate\Console\Command;

/**
 * P4.6 — show a tenant's objective weighting blend (normalised to percentages).
 * Read-only.
 */
class ObjectiveWeightingCommand extends Command
{
    protected $signature = 'objectives:weighting {tenant : Tenant id}';

    protected $description = "Show a tenant's multi-objective optimisation blend.";

    public function handle(WeightingRegistry $registry): int
    {
        $tenantId = (int) $this->argument('tenant');
        $weighting = $registry->weighting($tenantId);

        if ($weighting === []) {
            $this->info("No objective weighting for tenant {$tenantId} — outputs use equal weighting.");
            return self::SUCCESS;
        }

        $total = array_sum($weighting) ?: 1.0;
        $this->table(['objective', 'weight', 'share'],
            collect($weighting)->map(fn ($w, $o) => [$o, $w, round($w / $total * 100, 1) . '%'])->values()->all());

        return self::SUCCESS;
    }
}
