<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreFeature;
use App\Platform\Intelligence\Clustering\Strategies\DemandClustering;
use Tests\TestCase;

/**
 * Phase 3 — behavioural clustering does what structural cannot: it separates
 * stores that share format + region but trade differently (the premium vs
 * value-driven case), and labels each group from its members' tier profile.
 */
class DemandClusteringTest extends TestCase
{
    private function storeWithFeature(int $tenantId, string $code, array $attrs): Store
    {
        $store = Store::create([
            'tenant_id' => $tenantId, 'name' => $code, 'code' => $code,
            'format' => 'Hypermarket', 'region' => 'Dubai', // structurally identical
        ]);

        StoreFeature::create(array_merge([
            'tenant_id' => $tenantId, 'store_id' => $store->id,
            'window_days' => 90, 'computed_at' => now(),
        ], $attrs));

        return $store;
    }

    public function test_separates_behaviour_within_identical_attributes(): void
    {
        $t = $this->createTenant();

        $premium = [
            'revenue' => 90000, 'units' => 450, 'active_skus' => 2000, 'basket_count' => 180,
            'avg_daily_revenue' => 1000, 'avg_basket_value' => 500, 'avg_selling_price' => 200,
            'sku_productivity' => 300, 'promo_share' => 0.05, 'growth_ratio' => 1.0,
            'size_tier' => 'large', 'price_tier' => 'premium', 'basket_tier' => 'high',
            'dominant_segment' => 'smooth',
        ];
        $value = [
            'revenue' => 85000, 'units' => 4200, 'active_skus' => 1800, 'basket_count' => 1000,
            'avg_daily_revenue' => 950, 'avg_basket_value' => 80, 'avg_selling_price' => 20,
            'sku_productivity' => 250, 'promo_share' => 0.4, 'growth_ratio' => 1.0,
            'size_tier' => 'large', 'price_tier' => 'value', 'basket_tier' => 'low',
            'dominant_segment' => 'smooth',
        ];

        $a = $this->storeWithFeature($t->id, 'A', $premium);
        $c = $this->storeWithFeature($t->id, 'C', ['avg_basket_value' => 520, 'avg_selling_price' => 210] + $premium);
        $b = $this->storeWithFeature($t->id, 'B', $value);
        $d = $this->storeWithFeature($t->id, 'D', ['avg_basket_value' => 85, 'avg_selling_price' => 22] + $value);

        $groups = app(DemandClustering::class)->cluster($t->id);

        $this->assertCount(2, $groups); // n=4 -> k≈2

        $clusterOf = fn (int $id) => collect($groups)->first(fn ($g) => in_array($id, $g['store_ids'], true));

        $ga = $clusterOf($a->id);
        $this->assertNotNull($ga);
        // Premium stores together, value stores excluded.
        $this->assertContains($c->id, $ga['store_ids']);
        $this->assertNotContains($b->id, $ga['store_ids']);
        $this->assertNotContains($d->id, $ga['store_ids']);

        // Value stores share the other cluster.
        $gb = $clusterOf($b->id);
        $this->assertContains($d->id, $gb['store_ids']);

        // Labels are explainable from the tier profile.
        $this->assertStringContainsString('premium', $ga['label']);
        $this->assertStringContainsString('value', $gb['label']);
        $this->assertSame('premium', $ga['params']['price_tier']);
    }

    public function test_returns_nothing_without_features(): void
    {
        $t = $this->createTenant();
        Store::create(['tenant_id' => $t->id, 'name' => 'X', 'code' => 'X']); // no feature row

        $this->assertSame([], app(DemandClustering::class)->cluster($t->id));
    }
}
