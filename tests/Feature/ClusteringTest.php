<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreCluster;
use App\Platform\Intelligence\Clustering\ClusterService;
use Tests\TestCase;

/**
 * Platform\Intelligence\Clustering — the attribute strategy groups stores by
 * format + region, the rebuild is idempotent, and consumers can look a store up.
 */
class ClusteringTest extends TestCase
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

    public function test_attribute_strategy_groups_by_format_and_region(): void
    {
        $t = $this->createTenant();
        $a = $this->store($t->id, 'S1', 'Hypermarket', 'Dubai');
        $b = $this->store($t->id, 'S2', 'Hypermarket', 'Dubai');   // same group as A
        $c = $this->store($t->id, 'S3', 'Express', 'Dubai');       // different format
        $d = $this->store($t->id, 'S4', 'Hypermarket', 'Abu Dhabi'); // different region

        $written = app(ClusterService::class)->rebuild($t->id);

        $this->assertSame(3, $written);
        $this->assertSame(3, StoreCluster::where('tenant_id', $t->id)->count());

        $svc = app(ClusterService::class);
        $clusterA = $svc->clusterForStore($a->id);
        $this->assertNotNull($clusterA);
        // A and B share a cluster; C and D do not.
        $this->assertEqualsCanonicalizing(
            [$a->id, $b->id],
            $clusterA->stores()->pluck('stores.id')->all(),
        );
        $this->assertNotEquals($clusterA->id, $svc->clusterForStore($c->id)->id);
        $this->assertNotEquals($clusterA->id, $svc->clusterForStore($d->id)->id);
    }

    public function test_rebuild_is_idempotent(): void
    {
        $t = $this->createTenant();
        $this->store($t->id, 'S1', 'Hypermarket', 'Dubai');
        $this->store($t->id, 'S2', 'Express', 'Dubai');

        $svc = app(ClusterService::class);
        $svc->rebuild($t->id);
        $svc->rebuild($t->id);
        $svc->rebuild($t->id);

        // No accumulation across rebuilds.
        $this->assertSame(2, StoreCluster::where('tenant_id', $t->id)->count());
        $this->assertSame(
            2,
            \Illuminate\Support\Facades\DB::table('store_cluster_members')->count(),
        );
    }

    public function test_stores_without_attributes_fall_into_an_unspecified_group(): void
    {
        $t = $this->createTenant();
        $x = $this->store($t->id, 'S1', null, null);
        $y = $this->store($t->id, 'S2', '', '   ');

        app(ClusterService::class)->rebuild($t->id);

        $cluster = app(ClusterService::class)->clusterForStore($x->id);
        $this->assertNotNull($cluster);
        $this->assertEqualsCanonicalizing(
            [$x->id, $y->id],
            $cluster->stores()->pluck('stores.id')->all(),
        );
    }

    public function test_clusters_are_scoped_per_tenant(): void
    {
        $t1 = $this->createTenant();
        $t2 = $this->createTenant();
        $this->store($t1->id, 'A', 'Hypermarket', 'Dubai');
        $this->store($t2->id, 'B', 'Hypermarket', 'Dubai');

        $svc = app(ClusterService::class);
        $svc->rebuild($t1->id);

        // Rebuilding t1 must not touch t2.
        $this->assertSame(1, StoreCluster::where('tenant_id', $t1->id)->count());
        $this->assertSame(0, StoreCluster::where('tenant_id', $t2->id)->count());
    }
}
