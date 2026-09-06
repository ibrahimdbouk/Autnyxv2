<?php

namespace App\Console\Commands;

use App\Platform\Planning\ForecastBaseline;
use Illuminate\Console\Command;

/**
 * P2.4 — look up the ingested planning baseline (expected demand / planned order)
 * for a sku-store-day: the expectation detection measures reality against. Returns
 * nothing when planning has no view of that key, which is itself a signal.
 */
class PlanningBaselineCommand extends Command
{
    protected $signature = 'planning:baseline {tenant} {sku} {date} {--store=}';

    protected $description = "Show the ingested planning baseline (forecast + planned order) for a sku-store-day.";

    public function handle(ForecastBaseline $baseline): int
    {
        $tenantId = (int) $this->argument('tenant');
        $sku      = (string) $this->argument('sku');
        $date     = (string) $this->argument('date');
        $storeId  = $this->option('store') !== null ? (int) $this->option('store') : null;

        $demand = $baseline->expectedDemand($tenantId, $sku, $storeId, $date);
        $order  = $baseline->plannedOrder($tenantId, $sku, $storeId, $date);

        if ($demand === null && $order === null) {
            $this->warn("No planning baseline for tenant {$tenantId} sku {$sku} on {$date}.");
            return self::SUCCESS;
        }

        $this->info("Baseline for {$sku} on {$date}: expected demand=" . ($demand ?? '—') . ", planned order=" . ($order ?? '—') . '.');

        return self::SUCCESS;
    }
}
