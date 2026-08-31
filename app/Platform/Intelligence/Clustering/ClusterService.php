<?php

namespace App\Platform\Intelligence\Clustering;

use App\Models\StoreCluster;
use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The clustering façade every consumer uses — apps read clusters through this,
 * never the tables directly. Rebuild is idempotent: it replaces a tenant's
 * clusters for a given method inside one transaction.
 */
class ClusterService
{
    public function __construct(private ClusteringStrategyFactory $factory) {}

    /**
     * Rebuild a tenant's clusters using the configured (or given) strategy.
     * Returns the number of clusters written.
     */
    public function rebuild(int $tenantId, ?string $method = null): int
    {
        $strategy = $this->factory->make($method);
        $clusters = $strategy->cluster($tenantId);

        return DB::transaction(function () use ($tenantId, $strategy, $clusters) {
            // Replace, don't accumulate: drop this tenant+method's clusters
            // (members cascade) and recreate from the fresh computation.
            StoreCluster::query()
                ->where('tenant_id', $tenantId)
                ->where('method', $strategy->method())
                ->delete();

            $written = 0;
            foreach ($clusters as $c) {
                if (empty($c['store_ids'])) {
                    continue;
                }

                $cluster = StoreCluster::create([
                    'tenant_id' => $tenantId,
                    'method'    => $strategy->method(),
                    'key'       => $c['key'],
                    'label'     => $c['label'],
                    'params'    => $c['params'] ?? null,
                ]);

                $cluster->stores()->sync(array_values(array_unique($c['store_ids'])));
                $written++;
            }

            return $written;
        });
    }

    /** Rebuild every tenant. Returns [tenantId => clustersWritten]. */
    public function rebuildAll(?string $method = null): array
    {
        $out = [];
        Tenant::query()->orderBy('id')->pluck('id')->each(function (int $id) use (&$out, $method) {
            $out[$id] = $this->rebuild($id, $method);
        });

        return $out;
    }

    /** A tenant's clusters (with stores) for the given/default method. */
    public function clustersFor(int $tenantId, ?string $method = null): Collection
    {
        return StoreCluster::query()
            ->with('stores')
            ->where('tenant_id', $tenantId)
            ->where('method', $method ?? $this->factory->defaultMethod())
            ->get();
    }

    /** The cluster a store belongs to for the given/default method, or null. */
    public function clusterForStore(int $storeId, ?string $method = null): ?StoreCluster
    {
        return StoreCluster::query()
            ->where('method', $method ?? $this->factory->defaultMethod())
            ->whereHas('stores', fn ($q) => $q->where('stores.id', $storeId))
            ->first();
    }
}
