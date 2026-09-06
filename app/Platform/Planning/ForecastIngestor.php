<?php

namespace App\Platform\Planning;

use App\Models\PlanForecast;

/**
 * P2.4 (inbound) — ingest a batch of {@see ForecastPoint}s from a tenant's
 * planning system into the baseline. Upsert on the natural key
 * (tenant, sku, store, target_date, source) so re-ingesting a refreshed plan
 * replaces the prior point rather than duplicating it — the latest plan is the
 * active baseline. Returns the number of points written.
 */
class ForecastIngestor
{
    /**
     * @param  iterable<ForecastPoint>  $points
     */
    public function ingest(int $tenantId, iterable $points, string $source): int
    {
        $count = 0;

        foreach ($points as $point) {
            PlanForecast::updateOrCreate(
                [
                    'tenant_id'   => $tenantId,
                    'sku'         => $point->sku,
                    'store_id'    => $point->storeId,
                    'target_date' => $point->targetDate,
                    'source'      => $source,
                ],
                [
                    'forecast_qty'      => $point->forecastQty,
                    'planned_order_qty' => $point->plannedOrderQty,
                    'source_ref'        => $point->sourceRef,
                    'horizon_days'      => $point->horizonDays,
                    'generated_at'      => $point->generatedAt,
                ],
            );

            $count++;
        }

        return $count;
    }
}
