<?php

namespace Tests\Feature;

use App\Models\SalesTransaction;
use App\Models\Store;
use App\Models\StoreCluster;
use App\Platform\Intelligence\Clustering\ClusterService;
use Tests\TestCase;

/**
 * Store Clustering — user amendments (the Store Clustering page). Edits must
 * survive the nightly rebuild, a store lives in one cluster, reset regenerates,
 * and the page's numbers aggregate the group's trade.
 */
class ClusterCustomisationTest extends TestCase
{
    private function store(int $tenantId, string $code, ?string $format, ?string $region): Store
    {
        return Store::create([
            'tenant_id' => $tenantId,
            'name'      => $code,
            'code'      => $code,
            'format'    => $format,
            'region'    => $region,
        ]);
    }

    public function test_rebuild_skips_a_customised_tenant(): void
    {
        $t = $this->createTenant();
        $this->store($t->id, 'S1', 'Hypermarket', 'Dubai');

        $svc = app(ClusterService::class);
        $svc->rebuild($t->id);
        $this->assertSame(1, StoreCluster::where('tenant_id', $t->id)->count());

        // User customises, then a new store arrives and a nightly rebuild runs.
        $t->setClusteringCustomised(true);
        $this->store($t->id, 'S2', 'Express', 'Abu Dhabi');
        $svc->rebuild($t->id); // not forced

        // Their grouping is untouched (still 1 cluster); the new store is unassigned.
        $this->assertSame(1, StoreCluster::where('tenant_id', $t->id)->count());
        $this->assertNotEmpty($svc->unassignedStoreIds($t->id));
    }

    public function test_reset_regenerates_from_the_strategy(): void
    {
        $t = $this->createTenant();
        $this->store($t->id, 'S1', 'Hypermarket', 'Dubai');
        $this->store($t->id, 'S2', 'Express', 'Dubai');

        $svc = app(ClusterService::class);
        $svc->rebuild($t->id);
        $t->setClusteringCustomised(true);

        // Reset clears the flag and rebuilds even though the tenant was customised.
        $svc->resetToRecommended($t->id);

        $this->assertFalse($t->fresh()->clusteringCustomised());
        $this->assertSame(2, StoreCluster::where('tenant_id', $t->id)->count());
        $this->assertEmpty($svc->unassignedStoreIds($t->id));
    }

    public function test_enforce_single_membership_moves_a_store_out_of_other_clusters(): void
    {
        $t = $this->createTenant();
        $s = $this->store($t->id, 'S1', 'Hypermarket', 'Dubai');

        $a = StoreCluster::create(['tenant_id' => $t->id, 'method' => 'attribute', 'key' => 'a', 'label' => 'A']);
        $b = StoreCluster::create(['tenant_id' => $t->id, 'method' => 'attribute', 'key' => 'b', 'label' => 'B']);
        $a->stores()->attach($s->id);
        $b->stores()->attach($s->id); // wrongly in both

        app(ClusterService::class)->enforceSingleMembership($b->fresh());

        // Kept in B (the one enforced), removed from A.
        $this->assertTrue($b->stores()->where('stores.id', $s->id)->exists());
        $this->assertFalse($a->stores()->where('stores.id', $s->id)->exists());
        $this->assertTrue($t->fresh()->clusteringCustomised());
    }

    public function test_metrics_aggregate_member_store_trade(): void
    {
        $t = $this->createTenant();
        $s1 = $this->store($t->id, 'S1', 'Hypermarket', 'Dubai');
        $s2 = $this->store($t->id, 'S2', 'Hypermarket', 'Dubai');
        $other = $this->store($t->id, 'S3', 'Express', 'Dubai');

        foreach ([[$s1, 'SKU-A', 10, 100.0], [$s2, 'SKU-B', 5, 50.0], [$other, 'SKU-C', 99, 999.0]] as [$store, $sku, $qty, $amt]) {
            SalesTransaction::create([
                'tenant_id'    => $t->id,
                'store_id'     => $store->id,
                'sku'          => $sku,
                'quantity'     => $qty,
                'total_amount' => $amt,
                'date'         => now()->subDays(10)->toDateString(),
            ]);
        }

        $cluster = StoreCluster::create(['tenant_id' => $t->id, 'method' => 'attribute', 'key' => 'hd', 'label' => 'Hyper Dubai']);
        $cluster->stores()->attach([$s1->id, $s2->id]); // not the Express store

        $m = $cluster->metrics(90);

        $this->assertSame(2, $m['stores']);
        $this->assertSame(150.0, $m['revenue']);   // 100 + 50, S3 excluded
        $this->assertSame(15.0, $m['units']);      // 10 + 5
        $this->assertSame(2, $m['skus']);          // SKU-A, SKU-B
        $this->assertSame(75.0, $m['avg_revenue']); // 150 / 2
    }
}
