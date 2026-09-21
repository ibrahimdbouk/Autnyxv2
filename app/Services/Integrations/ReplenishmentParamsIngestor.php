<?php

namespace App\Services\Integrations;

use App\Models\ApiConnection;
use App\Models\ApiFeed;
use App\Models\SkuReplenishment;
use App\Models\Store;

/**
 * Routes a `replenishment_params` API feed into sku_replenishment as TENANT-SUPPLIED
 * (source = ingested) parameters — reorder point, safety stock, lead time,
 * order-up-to, service level — from an F&R tool (RELEX / Blue Yonder / Slimstock).
 *
 * Ingested rows are authoritative: the nightly ReplenishmentService compute skips
 * them and never sweeps them ("derived falls back, never overwrites"). Upsert on
 * the (tenant, sku, store) natural key so a refreshed feed replaces prior values.
 *
 * Expected canonical headers (from field_map): sku, and any of store/location,
 * reorder_point, safety_stock, lead_time_days, order_up_to, service_level, supplier.
 * store_id 0 = chain-level (matches the compute's convention).
 * See claude/api-integration-library.md.
 */
class ReplenishmentParamsIngestor
{
    /** @param iterable<int,array<string,mixed>> $rows */
    public function ingest(ApiConnection $connection, ApiFeed $feed, iterable $rows): int
    {
        $tenantId   = (int) $connection->tenant_id;
        $now        = now();
        $storeCache = [];
        $count      = 0;

        foreach ($rows as $row) {
            $sku = trim((string) ($row['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }

            $values = array_filter([
                'reorder_point'  => $this->num($row['reorder_point'] ?? null),
                'safety_stock'   => $this->num($row['safety_stock'] ?? null),
                'lead_time_days' => $this->num($row['lead_time_days'] ?? null),
                'order_up_to'    => $this->num($row['order_up_to'] ?? null),
                'service_level'  => $this->num($row['service_level'] ?? null),
                'supplier'       => isset($row['supplier']) ? (string) $row['supplier'] : null,
            ], fn ($v) => $v !== null);

            SkuReplenishment::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'sku'       => $sku,
                    'store_id'  => $this->resolveStore($tenantId, $row, $storeCache),
                ],
                array_merge($values, [
                    'source'      => SkuReplenishment::SOURCE_INGESTED,
                    'computed_at' => $now,
                ]),
            );

            $count++;
        }

        return $count;
    }

    private function num($value): ?float
    {
        return ($value === null || $value === '') ? null : (float) $value;
    }

    /** Store id by code or name; 0 = chain-level (matches sku_replenishment's convention). */
    private function resolveStore(int $tenantId, array $row, array &$cache): int
    {
        $ref = trim((string) ($row['store'] ?? $row['location'] ?? $row['store_code'] ?? ''));
        if ($ref === '') {
            return 0;
        }
        if (array_key_exists($ref, $cache)) {
            return $cache[$ref];
        }

        $store = Store::where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->where('code', $ref)->orWhere('name', $ref))
            ->first();

        return $cache[$ref] = ($store?->id ?? 0);
    }
}
