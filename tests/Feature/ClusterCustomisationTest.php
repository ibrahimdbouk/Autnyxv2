<?php

namespace Tests\Feature;

use App\Models\ClusterSet;
use App\Models\SalesTransaction;
use App\Models\Store;
use App\Models\StoreCluster;
use App\Platform\Intelligence\Clustering\ClusterService;
use Tests\TestCase;

/**
 * Phase 5 — the pins overlay (replacing the old tenant freeze) and cluster-set
 * versioning. A pinned store survives the nightly rebuild, untouched/new stores
 * still re-cluster freely, reset clears pins, and version bumps only on change.
 */
class ClusterCustomisationTest extends TestCase
{
    private function store(int $tenantId, string $code, string $format, string $region): Store
    {
        return Store::create([
            'tenant_id' => $tenantId, 'name' => $code, 'code' => $code,
            'format' => $format, 'region' => $region,
        ]);
    }

    public function test_a_pinned_store_survives_rebuild(): void
    {
        $t = $this->createTenant();
        $a = $this->store($t->id, 'A', 'Hypermarket', 'Dubai');
        $b = $this->store($t->id, 'B', 'Express', 'Dubai');

        $svc = app(ClusterService::class);
        $svc->rebuild($t->id);
        // Strategy puts A alone in the hypermarket group.
        $this->assertSame('hypermarket__dubai', $svc->clusterForStore($a->id)->key);

        // Pin A into the express group, then rebuild.
        $svc->recordMembership($t->id, 'general', [$a->id], 'express__dubai');
        $svc->rebuild($t->id);

        $ca = $svc->clusterForStore($a->id);
        $this->assertSame('express__dubai', $ca->key);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $ca->stores()->pluck('stores.id')->all());
    }

    public function test_new_stores_are_auto_placed_even_while_customised(): void
    {
        $t = $this->createTenant();
        $a = $this->store($t->id, 'A', 'Hypermarket', 'Dubai');
        $this->store($t->id, 'B', 'Express', 'Dubai');

        $svc = app(ClusterService::class);
        $svc->recordMembership($t->id, 'general', [$a->id], 'express__dubai'); // customised
        $svc->rebuild($t->id);

        // A new hypermarket arrives after customisation.
        $c = $this->store($t->id, 'C', 'Hypermarket', 'Dubai');
        $svc->rebuild($t->id);

        // The pin holds A in express; C is auto-placed by the strategy (the freeze
        // would have left C unassigned).
        $this->assertSame('express__dubai', $svc->clusterForStore($a->id)->key);
        $this->assertSame('hypermarket__dubai', $svc->clusterForStore($c->id)->key);
    }

    public function test_reset_clears_pins_and_regenerates(): void
    {
        $t = $this->createTenant();
        $a = $this->store($t->id, 'A', 'Hypermarket', 'Dubai');
        $this->store($t->id, 'B', 'Express', 'Dubai');

        $svc = app(ClusterService::class);
        $svc->recordMembership($t->id, 'general', [$a->id], 'express__dubai');
        $svc->rebuild($t->id);
        $this->assertTrue($svc->hasPins($t->id));

        $svc->resetToRecommended($t->id);

        $this->assertFalse($svc->hasPins($t->id));
        $this->assertSame('hypermarket__dubai', $svc->clusterForStore($a->id)->key);
    }

    public function test_a_custom_cluster_via_pins_persists(): void
    {
        $t = $this->createTenant();
        $a = $this->store($t->id, 'A', 'Hypermarket', 'Dubai');

        $svc = app(ClusterService::class);
        $svc->recordRename($t->id, 'general', 'custom-x', 'Flagships');
        $svc->recordMembership($t->id, 'general', [$a->id], 'custom-x');
        $svc->rebuild($t->id);

        $cx = $svc->clusterForStore($a->id);
        $this->assertSame('custom-x', $cx->key);
        $this->assertSame('Flagships', $cx->label);
    }

    public function test_version_bumps_only_on_material_change(): void
    {
        $t = $this->createTenant();
        $a = $this->store($t->id, 'A', 'Hypermarket', 'Dubai');
        $this->store($t->id, 'B', 'Express', 'Dubai');

        $svc = app(ClusterService::class);
        $svc->rebuild($t->id);
        $set = ClusterSet::where('tenant_id', $t->id)->where('strategy', 'attribute')->where('objective', 'general')->firstOrFail();
        $this->assertSame(1, $set->version);

        $svc->rebuild($t->id); // nothing changed
        $this->assertSame(1, $set->fresh()->version);

        $svc->recordMembership($t->id, 'general', [$a->id], 'express__dubai');
        $svc->rebuild($t->id); // grouping changed
        $this->assertSame(2, $set->fresh()->version);
    }

    public function test_metrics_aggregate_member_store_trade(): void
    {
        $t = $this->createTenant();
        $s1 = $this->store($t->id, 'S1', 'Hypermarket', 'Dubai');
        $s2 = $this->store($t->id, 'S2', 'Hypermarket', 'Dubai');

        foreach ([[$s1, 'SKU-A', 10, 100.0], [$s2, 'SKU-B', 5, 50.0]] as [$store, $sku, $qty, $amt]) {
            SalesTransaction::create([
                'tenant_id' => $t->id, 'store_id' => $store->id, 'sku' => $sku,
                'quantity' => $qty, 'total_amount' => $amt, 'date' => now()->subDays(10)->toDateString(),
            ]);
        }

        $cluster = StoreCluster::create([
            'tenant_id' => $t->id, 'method' => 'attribute', 'objective' => 'general', 'key' => 'hd', 'label' => 'Hyper Dubai',
        ]);
        $cluster->stores()->attach([$s1->id, $s2->id]);

        $m = $cluster->metrics(90);
        $this->assertSame(2, $m['stores']);
        $this->assertSame(150.0, $m['revenue']);
        $this->assertSame(15.0, $m['units']);
    }
}
