<?php

namespace App\Platform\Features;

use App\Models\FeatureValue;
use Illuminate\Support\Collection;

/**
 * P1.5 — the read/write API for the feature store. Producers write features
 * through put(); consumers (detection, clustering, forecasting) read the latest
 * value, the whole feature vector for an entity, or a time series — all through
 * one uniform interface, so a feature is defined and computed in one place.
 */
class FeatureStore
{
    /**
     * Write (or update, for the same identity) a feature value. Idempotent per
     * (entity, feature, as_of, version).
     */
    public function put(
        int $tenantId,
        string $entityType,
        string|int $entityKey,
        string $feature,
        float|int|string|null $value,
        ?string $asOf = null,
        int $version = 1,
    ): FeatureValue {
        $numeric = is_numeric($value);

        return FeatureValue::updateOrCreate(
            [
                'tenant_id'   => $tenantId,
                'entity_type' => $entityType,
                'entity_key'  => (string) $entityKey,
                'feature'     => $feature,
                'as_of'       => $asOf,
                'version'     => $version,
            ],
            [
                'value_num'   => $numeric ? (float) $value : null,
                'value_text'  => (! $numeric && $value !== null) ? (string) $value : null,
                'computed_at' => now(),
            ],
        );
    }

    /** The most recent value for one feature of an entity, or null. */
    public function latest(int $tenantId, string $entityType, string|int $entityKey, string $feature): ?FeatureValue
    {
        return FeatureValue::query()
            ->where('tenant_id', $tenantId)
            ->forEntity($entityType, (string) $entityKey)
            ->feature($feature)
            ->orderByDesc('as_of')
            ->orderByDesc('id')
            ->first();
    }

    /** The most recent value (numeric or text) for one feature of an entity. */
    public function get(int $tenantId, string $entityType, string|int $entityKey, string $feature): float|string|null
    {
        return $this->latest($tenantId, $entityType, $entityKey, $feature)?->value;
    }

    /**
     * The latest value of every feature for an entity — the feature vector.
     *
     * @return array<string,float|string|null>
     */
    public function vector(int $tenantId, string $entityType, string|int $entityKey): array
    {
        $rows = FeatureValue::query()
            ->where('tenant_id', $tenantId)
            ->forEntity($entityType, (string) $entityKey)
            ->orderByDesc('as_of')
            ->orderByDesc('id')
            ->get();

        $vector = [];
        foreach ($rows as $row) {
            if (! array_key_exists($row->feature, $vector)) {
                $vector[$row->feature] = $row->value;
            }
        }

        return $vector;
    }

    /**
     * The time series for one feature of an entity, oldest first.
     *
     * @return Collection<int,FeatureValue>
     */
    public function series(int $tenantId, string $entityType, string|int $entityKey, string $feature): Collection
    {
        return FeatureValue::query()
            ->where('tenant_id', $tenantId)
            ->forEntity($entityType, (string) $entityKey)
            ->feature($feature)
            ->orderBy('as_of')
            ->orderBy('id')
            ->get();
    }
}
