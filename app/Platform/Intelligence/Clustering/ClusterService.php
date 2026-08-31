<?php

namespace App\Platform\Intelligence\Clustering;

use App\Models\ClusterPin;
use App\Models\ClusterSet;
use App\Models\Store;
use App\Models\StoreCluster;
use App\Models\StoreFeature;
use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The clustering façade every consumer uses — apps read clusters through this,
 * never the tables directly.
 *
 * Rebuild is: compute fresh groups from the strategy → apply the manual PINS
 * overlay on top → materialise under a versioned cluster_set. This replaces the
 * old tenant-level freeze: a rebuild always runs, so untouched stores keep getting
 * fresh clustering and new stores are auto-placed, while a store the user pinned
 * stays where they put it. `version` bumps only when the grouping materially
 * changes, giving a stable reference rec-stamping (later) can point at.
 *
 * `objective` (Phase 1 seam) lets several cluster sets coexist per tenant; all
 * default to 'general'.
 */
class ClusterService
{
    public function __construct(private ClusteringStrategyFactory $factory) {}

    public function rebuild(
        int $tenantId,
        ?string $method = null,
        string $objective = StoreCluster::OBJECTIVE_GENERAL,
    ): int {
        // No explicit method → use the tenant's active strategy (attribute | demand).
        $method = $method ?? $this->activeMethod($tenantId);
        $strategy = $this->factory->make($method);
        $strategyMethod = $strategy->method();

        // 1. Fresh groups from the strategy, keyed by cluster key.
        $work = [];
        foreach ($strategy->cluster($tenantId) as $g) {
            $work[$g['key']] = [
                'key'       => $g['key'],
                'label'     => $g['label'],
                'store_ids' => array_map('intval', $g['store_ids']),
                'params'    => $g['params'] ?? null,
            ];
        }

        // 2. Apply the pins overlay.
        $work = $this->applyPins($tenantId, $objective, $work);

        // 3. Materialise under a versioned set (atomic).
        $signature = $this->signature($work);

        return DB::transaction(function () use ($tenantId, $strategyMethod, $objective, $work, $signature) {
            $set = ClusterSet::firstOrNew([
                'tenant_id' => $tenantId,
                'strategy'  => $strategyMethod,
                'objective' => $objective,
            ]);
            $set->version = ! $set->exists ? 1 : ($set->signature !== $signature ? $set->version + 1 : $set->version);
            $set->signature = $signature;
            $set->computed_at = now();
            $set->save();

            StoreCluster::query()
                ->where('tenant_id', $tenantId)
                ->where('method', $strategyMethod)
                ->where('objective', $objective)
                ->delete();

            $written = 0;
            foreach ($work as $c) {
                if ($c['store_ids'] === []) {
                    continue;
                }
                $cluster = StoreCluster::create([
                    'tenant_id'      => $tenantId,
                    'cluster_set_id' => $set->id,
                    'method'         => $strategyMethod,
                    'objective'      => $objective,
                    'key'            => $c['key'],
                    'label'          => $c['label'],
                    'params'         => $c['params'] ?? null,
                ]);
                $cluster->stores()->sync(array_values(array_unique($c['store_ids'])));
                $written++;
            }

            return $written;
        });
    }

    /** Overlay membership + rename pins onto the strategy's fresh grouping. */
    private function applyPins(int $tenantId, string $objective, array $work): array
    {
        $pins = ClusterPin::query()
            ->where('tenant_id', $tenantId)
            ->where('objective', $objective)
            ->get();

        $renames = $pins->where('pin_type', ClusterPin::TYPE_RENAME)->keyBy('target_key');

        foreach ($pins->where('pin_type', ClusterPin::TYPE_MEMBERSHIP) as $pin) {
            $storeId = (int) $pin->store_id;
            $key = $pin->target_key;

            // Pull the store out of whatever strategy cluster it landed in.
            foreach ($work as &$c) {
                $c['store_ids'] = array_values(array_filter($c['store_ids'], fn ($id) => $id !== $storeId));
            }
            unset($c);

            // Ensure the pinned target exists (custom clusters are pin-only).
            if (! isset($work[$key])) {
                $work[$key] = [
                    'key'       => $key,
                    'label'     => $renames->has($key) ? $renames[$key]->label : 'Custom group',
                    'store_ids' => [],
                    'params'    => ['custom' => true],
                ];
            }
            $work[$key]['store_ids'][] = $storeId;
        }

        foreach ($renames as $key => $pin) {
            if (isset($work[$key]) && $pin->label) {
                $work[$key]['label'] = $pin->label;
            }
        }

        return array_filter($work, fn ($c) => $c['store_ids'] !== []);
    }

    private function signature(array $work): string
    {
        $parts = [];
        foreach ($work as $c) {
            $ids = array_map('intval', $c['store_ids']);
            sort($ids);
            $parts[$c['key']] = $c['key'] . '=' . implode(',', $ids);
        }
        ksort($parts);

        return md5(implode('|', $parts));
    }

    /**
     * Rebuild every tenant with EACH tenant's active strategy (so a tenant on
     * behavioural clustering gets demand clusters, one on structural gets attribute).
     * An explicit $method overrides that for all tenants. Returns [tenantId => count].
     */
    public function rebuildAll(?string $method = null, string $objective = StoreCluster::OBJECTIVE_GENERAL): array
    {
        $out = [];
        Tenant::query()->orderBy('id')->pluck('id')->each(function (int $id) use (&$out, $method, $objective) {
            $out[$id] = $this->rebuild($id, $method, $objective); // rebuild() resolves the active strategy when $method is null
        });

        return $out;
    }

    // ---------- Active strategy (per tenant) ----------

    /** The clustering strategy this tenant is on ('attribute' | 'demand'), falling back to config. */
    public function activeMethod(int $tenantId): string
    {
        $chosen = Tenant::find($tenantId)?->settings['clustering_strategy'] ?? null;
        $valid = array_keys((array) config('clustering.strategies', []));

        return ($chosen && in_array($chosen, $valid, true))
            ? $chosen
            : (string) config('clustering.strategy', 'attribute');
    }

    /**
     * Switch a tenant to a clustering strategy. Because pins reference the current
     * strategy's cluster keys, changing strategy clears them for a clean switch.
     */
    public function setActiveMethod(int $tenantId, string $method): void
    {
        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            return;
        }

        $changed = $this->activeMethod($tenantId) !== $method;

        $settings = $tenant->settings ?? [];
        $settings['clustering_strategy'] = $method;
        $tenant->settings = $settings;
        $tenant->save();

        if ($changed) {
            ClusterPin::query()
                ->where('tenant_id', $tenantId)
                ->where('objective', StoreCluster::OBJECTIVE_GENERAL)
                ->delete();
        }
    }

    /**
     * Compute an attribute-vs-demand comparison for a tenant WITHOUT persisting —
     * strategies are pure. Powers the "preview before you switch" screen.
     *
     * @return array{active:string, demand_available:bool, attribute:array, demand:array, crosstab:array}
     */
    public function compare(int $tenantId): array
    {
        $attribute = $this->factory->make('attribute')->cluster($tenantId);
        $demand = $this->factory->make('demand')->cluster($tenantId);

        $demandByStore = [];
        foreach ($demand as $group) {
            foreach ($group['store_ids'] as $storeId) {
                $demandByStore[(int) $storeId] = $group['label'];
            }
        }

        $crosstab = [];
        foreach ($attribute as $group) {
            $split = [];
            foreach ($group['store_ids'] as $storeId) {
                $label = $demandByStore[(int) $storeId] ?? '(unprofiled)';
                $split[$label] = ($split[$label] ?? 0) + 1;
            }
            arsort($split);
            $crosstab[] = [
                'label'    => $group['label'],
                'count'    => count($group['store_ids']),
                'split'    => $split,
                'is_split' => count($split) > 1,
            ];
        }

        return [
            'active'           => $this->activeMethod($tenantId),
            'demand_available' => StoreFeature::query()->where('tenant_id', $tenantId)->where('revenue', '>', 0)->exists(),
            'attribute'        => $attribute,
            'demand'           => $demand,
            'crosstab'         => $crosstab,
        ];
    }

    /** A tenant's clusters (with stores) for the given/default method + objective. */
    public function clustersFor(
        int $tenantId,
        ?string $method = null,
        string $objective = StoreCluster::OBJECTIVE_GENERAL,
    ): Collection {
        return StoreCluster::query()
            ->with('stores')
            ->where('tenant_id', $tenantId)
            ->where('method', $method ?? $this->factory->defaultMethod())
            ->where('objective', $objective)
            ->get();
    }

    /** The cluster a store belongs to for the given/default method + objective, or null. */
    public function clusterForStore(
        int $storeId,
        ?string $method = null,
        string $objective = StoreCluster::OBJECTIVE_GENERAL,
    ): ?StoreCluster {
        return StoreCluster::query()
            ->where('method', $method ?? $this->factory->defaultMethod())
            ->where('objective', $objective)
            ->whereHas('stores', fn ($q) => $q->where('stores.id', $storeId))
            ->first();
    }

    /** Store ids in this tenant+method+objective that are not in any cluster. */
    public function unassignedStoreIds(
        int $tenantId,
        ?string $method = null,
        string $objective = StoreCluster::OBJECTIVE_GENERAL,
    ): array {
        $method = $method ?? $this->factory->defaultMethod();

        $clustered = DB::table('store_cluster_members')
            ->join('store_clusters', 'store_clusters.id', '=', 'store_cluster_members.store_cluster_id')
            ->where('store_clusters.tenant_id', $tenantId)
            ->where('store_clusters.method', $method)
            ->where('store_clusters.objective', $objective)
            ->pluck('store_cluster_members.store_id');

        return Store::query()
            ->where('tenant_id', $tenantId)
            ->whereNotIn('id', $clustered)
            ->pluck('id')
            ->all();
    }

    // ---------- Manual overlay (pins) ----------

    /** True if the tenant has any manual pins for this objective (i.e. customised). */
    public function hasPins(int $tenantId, string $objective = StoreCluster::OBJECTIVE_GENERAL): bool
    {
        return ClusterPin::query()
            ->where('tenant_id', $tenantId)
            ->where('objective', $objective)
            ->exists();
    }

    /** Pin each store into the given cluster key (one membership pin per store). */
    public function recordMembership(int $tenantId, string $objective, array $storeIds, string $targetKey): void
    {
        foreach ($storeIds as $storeId) {
            ClusterPin::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'objective' => $objective,
                    'store_id'  => (int) $storeId,
                    'pin_type'  => ClusterPin::TYPE_MEMBERSHIP,
                ],
                ['target_key' => $targetKey],
            );
        }
    }

    /** Pin a label onto a cluster key. */
    public function recordRename(int $tenantId, string $objective, string $targetKey, ?string $label): void
    {
        ClusterPin::updateOrCreate(
            [
                'tenant_id'  => $tenantId,
                'objective'  => $objective,
                'store_id'   => null,
                'pin_type'   => ClusterPin::TYPE_RENAME,
                'target_key' => $targetKey,
            ],
            ['label' => $label],
        );
    }

    /**
     * Record a user's edit of a materialised cluster as pins (so it survives the
     * nightly rebuild), then keep the materialised grouping single-membership.
     */
    public function applyManualEdit(StoreCluster $cluster): void
    {
        $objective = $cluster->objective ?? StoreCluster::OBJECTIVE_GENERAL;
        $storeIds = $cluster->stores()->pluck('stores.id')->all();

        $this->recordMembership($cluster->tenant_id, $objective, $storeIds, $cluster->key);
        $this->recordRename($cluster->tenant_id, $objective, $cluster->key, $cluster->label);
        $this->enforceSingleMembership($cluster);
    }

    /** Materialised single-membership: detach this cluster's stores from siblings in the same set. */
    public function enforceSingleMembership(StoreCluster $cluster): void
    {
        $storeIds = $cluster->stores()->pluck('stores.id')->all();
        if ($storeIds === []) {
            return;
        }

        $siblingIds = StoreCluster::query()
            ->where('tenant_id', $cluster->tenant_id)
            ->where('method', $cluster->method)
            ->where('objective', $cluster->objective ?? StoreCluster::OBJECTIVE_GENERAL)
            ->where('id', '!=', $cluster->id)
            ->pluck('id');

        DB::table('store_cluster_members')
            ->whereIn('store_id', $storeIds)
            ->whereIn('store_cluster_id', $siblingIds)
            ->delete();
    }

    /** Remove all pins referencing a cluster key (used when a cluster is deleted). */
    public function removeClusterPins(int $tenantId, string $objective, string $targetKey): void
    {
        ClusterPin::query()
            ->where('tenant_id', $tenantId)
            ->where('objective', $objective)
            ->where('target_key', $targetKey)
            ->delete();
    }

    /** Discard all of a tenant's manual pins for an objective and regenerate. */
    public function resetToRecommended(
        int $tenantId,
        ?string $method = null,
        string $objective = StoreCluster::OBJECTIVE_GENERAL,
    ): int {
        ClusterPin::query()
            ->where('tenant_id', $tenantId)
            ->where('objective', $objective)
            ->delete();

        return $this->rebuild($tenantId, $method, $objective);
    }
}
