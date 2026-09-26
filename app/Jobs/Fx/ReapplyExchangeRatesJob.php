<?php

namespace App\Jobs\Fx;

use App\Services\Sales\SalesDailyAggregator;
use App\Services\Supply\PurchaseOrderNormalizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;

/**
 * W13 — after exchange rates change: PO costs back to as-ordered and converted
 * again, and the daily / weekly / monthly sales rebuilt at the new rates.
 */
class ReapplyExchangeRatesJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 1800;

    public function __construct(public readonly int $tenantId) {}

    public function handle(SalesDailyAggregator $daily, PurchaseOrderNormalizer $po): void
    {
        DB::table('purchase_orders')->where('tenant_id', $this->tenantId)->whereNotNull('fx_rate')
            ->update(['unit_cost' => DB::raw('unit_cost_original'), 'unit_cost_original' => null, 'fx_rate' => null]);
        $po->normalize($this->tenantId);
        $daily->rebuildForTenant($this->tenantId);
    }
}
