<?php

namespace Tests\Feature;

use App\Models\LocationNode;
use App\Models\Store;
use Tests\TestCase;

/**
 * P1.1 — the canonical Location hierarchy: roll-up (store ids under a node) and
 * drill-down/ancestry work at any tier, which is the DoD for the entity model.
 */
class LocationHierarchyTest extends TestCase
{
    public function test_region_rolls_up_to_its_stores(): void
    {
        $tenant = $this->createTenant();

        $region = LocationNode::create(['tenant_id' => $tenant->id, 'type' => LocationNode::TYPE_REGION, 'name' => 'North']);
        $s1 = Store::create(['tenant_id' => $tenant->id, 'name' => 'S1', 'region' => 'North']);
        $s2 = Store::create(['tenant_id' => $tenant->id, 'name' => 'S2', 'region' => 'North']);
        $n1 = LocationNode::create(['tenant_id' => $tenant->id, 'type' => LocationNode::TYPE_STORE, 'name' => 'S1', 'parent_id' => $region->id, 'store_id' => $s1->id]);
        $n2 = LocationNode::create(['tenant_id' => $tenant->id, 'type' => LocationNode::TYPE_STORE, 'name' => 'S2', 'parent_id' => $region->id, 'store_id' => $s2->id]);

        $this->assertEqualsCanonicalizing([$s1->id, $s2->id], $region->storeIds());
        $this->assertEqualsCanonicalizing([$n1->id, $n2->id], $region->descendantIds());
        $this->assertSame([$s1->id], $n1->storeIds(), 'a leaf rolls up to just itself');

        // store → canonical node link
        $this->assertSame($n1->id, Store::find($s1->id)->locationNode->id);
    }

    public function test_three_tier_banner_region_store_rollup_and_ancestry(): void
    {
        $tenant = $this->createTenant();

        $banner = LocationNode::create(['tenant_id' => $tenant->id, 'type' => LocationNode::TYPE_BANNER, 'name' => 'Acme']);
        $region = LocationNode::create(['tenant_id' => $tenant->id, 'type' => LocationNode::TYPE_REGION, 'name' => 'North', 'parent_id' => $banner->id]);
        $store  = Store::create(['tenant_id' => $tenant->id, 'name' => 'S1']);
        $leaf   = LocationNode::create(['tenant_id' => $tenant->id, 'type' => LocationNode::TYPE_STORE, 'name' => 'S1', 'parent_id' => $region->id, 'store_id' => $store->id]);

        // Roll up from the top of the tree to the operational stores.
        $this->assertSame([$store->id], $banner->storeIds());
        $this->assertEqualsCanonicalizing([$region->id, $leaf->id], $banner->descendantIds());

        // Drill up: ancestry is leaf → root.
        $this->assertSame([$region->id, $banner->id], $leaf->ancestors()->pluck('id')->all());
    }
}
