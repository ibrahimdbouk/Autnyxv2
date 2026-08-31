<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreCluster;
use App\Models\StoreFeature;
use App\Platform\Intelligence\Clustering\ClusterService;
use Tests\TestCase;

/**
 * Phase 2 — per-tenant clustering strategy: default, switching (which clears
 * pins), the nightly rebuild honouring each tenant's choice, and the
 * attribute-vs-demand comparison.
 */
class ClusterActivationTest extends TestCase
{
    private function store(int $tenantId, string $code): Store
    {
        return Store::create([
            'tenant_id' => $tenantId, 'name' => $code, 'code' => $code,
            'format' => 'Hypermarket', 'region' => 'Dubai',
        ]);
    }

    private function feature(int $tenantId, Store $store, array $attrs): void
    {
        StoreFeature::create(array_merge([
            'tenant_id' => $tenantId, 'store_id' => $store->id, 'window_days' => 90, 'computed_at' => now(),
            'revenue' => 50000, 'active_skus' => 1000, 'basket_count' => 100,
            'avg_daily_revenue' => 500, 'sku_productivity' => 250, 'promo_share' => 0.1, 'growth_ratio' => 1.0,
        ], $attrs));
    }

    public function test_active_method_defaults_to_config(): void
    {
        $t = $this->createTenant();
        $this->assertSame('attribute', app(ClusterService::class)->activeMethod($t->id));
    }

    public function test_invalid_stored_method_falls_back(): void
    {
        $t = $this->createTenant(['settings' => ['clustering_strategy' => 'bogus']]);
        $this->assertSame('attribute', app(ClusterService::class)->activeMethod($t->id));
    }

    public function test_switching_strategy_sets_it_and_clears_pins(): void
    {
        $t = $this->createTenant();
        $a = $this->store($t->id, 'A');

        $svc = app(ClusterService::class);
        $svc->recordMembership($t->id, 'general', [$a->id], 'somekey');
        $this->assertTrue($svc->hasPins($t->id));

        $svc->setActiveMethod($t->id, 'demand');

        $this->assertSame('demand', $svc->activeMethod($t->id));
        $this->assertFalse($svc->hasPins($t->id)); // pins referenced the old strategy's keys
    }

    public function test_rebuild_all_uses_each_tenants_active_strategy(): void
    {
        $t1 = $this->createTenant();
        $t2 = $this->createTenant();

        $s1 = $this->store($t1->id, 'A');
        $s2 = $this->store($t1->id, 'B');
        $this->feature($t1->id, $s1, ['avg_basket_value' => 500, 'avg_selling_price' => 200]);
        $this->feature($t1->id, $s2, ['avg_basket_value' => 80, 'avg_selling_price' => 20]);
        $this->store($t2->id, 'C'); // t2 stays on attribute

        $svc = app(ClusterService::class);
        $svc->setActiveMethod($t1->id, 'demand');
        $svc->rebuildAll();

        $this->assertDatabaseHas('store_clusters', ['tenant_id' => $t1->id, 'method' => 'demand']);
        $this->assertDatabaseHas('store_clusters', ['tenant_id' => $t2->id, 'method' => 'attribute']);
        $this->assertDatabaseMissing('store_clusters', ['tenant_id' => $t1->id, 'method' => 'attribute']);
    }

    public function test_compare_reports_behavioural_split(): void
    {
        $t = $this->createTenant();
        // Four structurally identical stores; two premium, two value.
        $a = $this->store($t->id, 'A');
        $c = $this->store($t->id, 'C');
        $b = $this->store($t->id, 'B');
        $d = $this->store($t->id, 'D');
        $this->feature($t->id, $a, ['avg_basket_value' => 500, 'avg_selling_price' => 200, 'price_tier' => 'premium']);
        $this->feature($t->id, $c, ['avg_basket_value' => 520, 'avg_selling_price' => 210, 'price_tier' => 'premium']);
        $this->feature($t->id, $b, ['avg_basket_value' => 80, 'avg_selling_price' => 20, 'price_tier' => 'value']);
        $this->feature($t->id, $d, ['avg_basket_value' => 85, 'avg_selling_price' => 22, 'price_tier' => 'value']);

        $cmp = app(ClusterService::class)->compare($t->id);

        $this->assertTrue($cmp['demand_available']);
        $this->assertCount(1, $cmp['attribute']);  // all four share format+region
        $this->assertCount(2, $cmp['demand']);      // behaviour splits them
        $this->assertTrue($cmp['crosstab'][0]['is_split']);
    }
}
