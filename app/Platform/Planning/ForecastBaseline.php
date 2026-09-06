<?php

namespace App\Platform\Planning;

use App\Models\PlanForecast;

/**
 * P2.4 (inbound) — read side of the planning baseline. This is what makes the
 * ingested plan usable as the *expectation* detection measures reality against:
 * "what did planning say demand / the order should be for this sku-store-day?"
 * Detection and the metric layer read expected demand from here rather than
 * re-deriving it. When several sources exist, the most recently updated point
 * wins (the freshest plan).
 */
class ForecastBaseline
{
    /** Expected demand for a sku/store/day, or null when planning has no view. */
    public function expectedDemand(int $tenantId, string $sku, ?int $storeId, string $date): ?float
    {
        return $this->point($tenantId, $sku, $storeId, $date)?->forecast_qty;
    }

    /** Planned order quantity for a sku/store/day, or null when none planned. */
    public function plannedOrder(int $tenantId, string $sku, ?int $storeId, string $date): ?float
    {
        return $this->point($tenantId, $sku, $storeId, $date)?->planned_order_qty;
    }

    public function point(int $tenantId, string $sku, ?int $storeId, string $date): ?PlanForecast
    {
        return PlanForecast::query()
            ->where('tenant_id', $tenantId)
            ->where('sku', $sku)
            ->where('store_id', $storeId)
            ->whereDate('target_date', $date)
            ->latest('updated_at')
            ->first();
    }
}
