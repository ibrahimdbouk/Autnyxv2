<?php

namespace App\Services\Integrations;

use App\Models\ApiConnection;
use App\Models\ApiFeed;
use App\Models\Store;
use App\Platform\Planning\ForecastIngestor;
use App\Platform\Planning\ForecastPoint;
use Carbon\Carbon;

/**
 * Routes a `demand_forecast` API feed into the planning baseline: maps each
 * connector row (already keyed by canonical forecast headers via the feed's
 * field_map) into a canonical {@see ForecastPoint} and hands them to the existing
 * {@see ForecastIngestor}, which upserts `plan_forecasts`. This is what turns an
 * F&R tool's plan (RELEX / Blue Yonder / Slimstock) into the baseline detection
 * measures reality against — kept out of the transactional import pipeline.
 *
 * Expected canonical headers (from field_map): sku, target_date, forecast_qty,
 * and optionally store/location, planned_order_qty, horizon_days, generated_at,
 * source_ref. See claude/api-integration-library.md.
 */
class ForecastFeedIngestor
{
    public function __construct(private ForecastIngestor $ingestor)
    {
    }

    /**
     * @param  iterable<int,array<string,mixed>>  $rows
     * @return int  forecast points written
     */
    public function ingest(ApiConnection $connection, ApiFeed $feed, iterable $rows): int
    {
        $tenantId   = (int) $connection->tenant_id;
        $storeCache = [];
        $points     = [];

        foreach ($rows as $row) {
            $sku        = trim((string) ($row['sku'] ?? ''));
            $targetDate = $this->toDate($row['target_date'] ?? null);
            if ($sku === '' || $targetDate === null) {
                continue; // a forecast point needs at least a SKU and a date
            }

            $points[] = new ForecastPoint(
                sku: $sku,
                targetDate: $targetDate,
                forecastQty: (float) ($row['forecast_qty'] ?? 0),
                storeId: $this->resolveStore($tenantId, $row, $storeCache),
                plannedOrderQty: isset($row['planned_order_qty']) ? (float) $row['planned_order_qty'] : null,
                sourceRef: isset($row['source_ref']) ? (string) $row['source_ref'] : null,
                horizonDays: isset($row['horizon_days']) ? (int) $row['horizon_days'] : null,
                generatedAt: $this->toIso($row['generated_at'] ?? null),
            );
        }

        return $this->ingestor->ingest($tenantId, $points, (string) $connection->provider);
    }

    /** Resolve a store id from the row's store/location (by code or name); null = chain-level. */
    private function resolveStore(int $tenantId, array $row, array &$cache): ?int
    {
        $ref = trim((string) ($row['store'] ?? $row['location'] ?? $row['store_code'] ?? ''));
        if ($ref === '') {
            return null;
        }
        if (array_key_exists($ref, $cache)) {
            return $cache[$ref];
        }

        $store = Store::where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->where('code', $ref)->orWhere('name', $ref))
            ->first();

        return $cache[$ref] = $store?->id;
    }

    private function toDate($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function toIso($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Carbon::parse((string) $value)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }
}
