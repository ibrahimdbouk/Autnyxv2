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
     *
     * Skips tenants that have customised their clusters (unless $force), so a
     * user's manual grouping is never clobbered by the nightly rebuild. A reset
     * clears the flag and forces a fresh rebuild.
     */
    public function rebuild(int $tenantId, ?string $method = null, bool $force = false): int
    {
        $strategy = $this->factory->make($method);

        if (! $force) {
            $tenant = Tenant::find($tenantId);
            if ($tenant && $tenant->clusteringCustomised()) {
                return StoreCluster::query()
                    ->where('tenant_id', $tenantId)
                    ->where('method', $strategy->method())
                    ->count();
            }
        }

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

    // ---------- Manual amendment (user edits from the Store Clustering page) ----------

    /** Mark a tenant's clusters as user-customised so the nightly rebuild leaves them alone. */
    public function markCustomised(int $tenantId): void
    {
        Tenant::find($tenantId)?->setClusteringCustomised(true);
    }

    /**
     * A store belongs to exactly one cluster: after a cluster's membership changes,
     * detach its stores from every sibling cluster (same tenant + method). Also
     * marks the tenant customised.
     */
    public function enforceSingleMembership(StoreCluster $cluster): void
    {
        $storeIds = $cluster->stores()->pluck('stores.id')->all();

        if ($storeIds !== []) {
            $siblingIds = StoreCluster::query()
                ->where('tenant_id', $cluster->tenant_id)
                ->where('method', $cluster->method)
                ->where('id', '!=', $cluster->id)
                ->pluck('id');

            DB::table('store_cluster_members')
                ->whereIn('store_id', $storeIds)
                ->whereIn('store_cluster_id', $siblingIds)
                ->delete();
        }

        $this->markCustomised($cluster->tenant_id);
    }

    /** Store ids in this tenant+method that are not in any cluster (need assigning). */
    public function unassignedStoreIds(int $tenantId, ?string $method = null): array
    {
        $method = $method ?? $this->factory->defaultMethod();

        $clustered = DB::table('store_cluster_members')
            ->join('store_clusters', 'store_clusters.id', '=', 'store_cluster_members.store_cluster_id')
            ->where('store_clusters.tenant_id', $tenantId)
            ->where('store_clusters.method', $method)
            ->pluck('store_cluster_members.store_id');

        return \App\Models\Store::query()
            ->where('tenant_id', $tenantId)
            ->whereNotIn('id', $clustered)
            ->pluck('id')
            ->all();
    }

    /** Discard the tenant's customisations and regenerate from the strategy. */
    public function resetToRecommended(int $tenantId, ?string $method = null): int
    {
        Tenant::find($tenantId)?->setClusteringCustomised(false);

        return $this->rebuild($tenantId, $method, force: true);
    }
}
