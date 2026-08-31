<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreCluster;
use App\Platform\Intelligence\Clustering\ClusterService;
use Tests\TestCase;

/**
 * Phase 1 seam — clusters carry an `objective`, default 'general', and the
 * service read API filters by it so multiple objective sets can coexist per tenant.
 */
class ClusterObjectiveSeamTest extends TestCase
{
    private function store(int $tenantId, string $code): Store
    {
        return Store::create([
            'tenant_id' => $tenantId, 'name' => $code, 'code' => $code,
            'format' => 'Hypermarket', 'region' => 'Dubai',
        ]);
    }

    public function test_rebuild_writes_the_general_objective(): void
    {
        $t = $this->createTenant();
        $this->store($t->id, 'S1');

        app(ClusterService::class)->rebuild($t->id);

        $this->assertDatabaseHas('store_clusters', [
            'tenant_id' => $t->id, 'objective' => StoreCluster::OBJECTIVE_GENERAL,
        ]);

        $svc = app(ClusterService::class);
        $this->assertGreaterThan(0, $svc->clustersFor($t->id)->count());
        // A different objective has no clusters yet.
        $this->assertCount(0, $svc->clustersFor($t->id, objective: 'assortment'));
    }

    public function test_objectives_coexist_and_are_filtered_by_the_read_api(): void
    {
        $t = $this->createTenant();
        $s = $this->store($t->id, 'S1');

        $svc = app(ClusterService::class);
        $svc->rebuild($t->id); // general

        // A separate assortment-objective cluster for the same store.
        $assort = StoreCluster::create([
            'tenant_id' => $t->id, 'method' => 'attribute', 'objective' => 'assortment',
            'key' => 'a', 'label' => 'Assortment peers',
        ]);
        $assort->stores()->attach($s->id);

        $general = $svc->clusterForStore($s->id);
        $this->assertSame(StoreCluster::OBJECTIVE_GENERAL, $general->objective);

        $found = $svc->clusterForStore($s->id, objective: 'assortment');
        $this->assertSame($assort->id, $found->id);

        // The store is unassigned under a third objective it has no cluster in.
        $this->assertContains($s->id, $svc->unassignedStoreIds($t->id, objective: 'promo'));
    }
}
