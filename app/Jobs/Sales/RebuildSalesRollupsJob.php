<?php

namespace App\Jobs\Sales;

use App\Services\Sales\SalesRollupAggregator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Root Cause drivers — a product file moved SKUs between categories: rebuild category_daily. */
class RebuildSalesRollupsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public int $uniqueFor = 1800;

    public function __construct(public int $tenantId, public array $what = [SalesRollupAggregator::CATEGORY])
    {
    }

    public function uniqueId(): string
    {
        return 'rollups-' . $this->tenantId . '-' . implode(',', $this->what);
    }

    public function handle(SalesRollupAggregator $agg): void
    {
        $agg->rebuildTenant($this->tenantId, $this->what);
    }
}
