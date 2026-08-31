<?php

namespace App\Platform\Intelligence\Clustering\Strategies;

use App\Models\Store;
use App\Platform\Intelligence\Clustering\ClusteringStrategy;
use Illuminate\Support\Str;

/**
 * v0 clustering — group stores by their master-data attributes: format + region
 * (both already on the store master). Zero ML, zero tuning, no data-maturity
 * dependency: it works the day a tenant onboards and gives every consumer a real
 * peer set. DemandClustering (v1) swaps in behind the same interface once there
 * is enough sales history to justify it.
 */
class AttributeClustering implements ClusteringStrategy
{
    public function method(): string
    {
        return 'attribute';
    }

    public function cluster(int $tenantId): array
    {
        $groups = [];

        Store::query()
            ->where('tenant_id', $tenantId)
            ->get(['id', 'format', 'region'])
            ->each(function (Store $store) use (&$groups) {
                $format = $this->normalise($store->format);
                $region = $this->normalise($store->region);
                $key = Str::slug($format) . '__' . Str::slug($region);

                if (! isset($groups[$key])) {
                    $groups[$key] = [
                        'key'       => $key,
                        'label'     => $this->titleize($format) . ' · ' . $this->titleize($region),
                        'store_ids' => [],
                        'params'    => ['format' => $format, 'region' => $region],
                    ];
                }

                $groups[$key]['store_ids'][] = $store->id;
            });

        return array_values($groups);
    }

    private function normalise(?string $value): string
    {
        $value = trim((string) $value);

        return $value === '' ? 'unspecified' : $value;
    }

    private function titleize(string $value): string
    {
        return $value === 'unspecified' ? 'Unspecified' : Str::title($value);
    }
}
