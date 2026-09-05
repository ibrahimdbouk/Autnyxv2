<?php

namespace Tests\Feature;

use App\Models\FeatureValue;
use App\Platform\Features\FeatureStore;
use Tests\TestCase;

/**
 * P1.5 — the feature store: uniform put/get, latest-wins reads, the feature
 * vector for an entity, a per-feature time series, and idempotent writes per day.
 */
class FeatureStoreTest extends TestCase
{
    public function test_put_get_vector_and_series(): void
    {
        $tenant = $this->createTenant();
        $store  = app(FeatureStore::class);

        $store->put($tenant->id, 'store', 1, 'avg_basket_value', 24.5, '2026-03-01');
        $store->put($tenant->id, 'store', 1, 'avg_basket_value', 26.0, '2026-03-08'); // newer
        $store->put($tenant->id, 'store', 1, 'size_tier', 'large', '2026-03-08');

        // get() returns the latest numeric value.
        $this->assertSame(26.0, (float) $store->get($tenant->id, 'store', 1, 'avg_basket_value'));

        // vector() returns the latest of every feature for the entity.
        $vector = $store->vector($tenant->id, 'store', 1);
        $this->assertEqualsCanonicalizing(['avg_basket_value', 'size_tier'], array_keys($vector));
        $this->assertSame('large', $vector['size_tier']);

        // series() returns the full time series for one feature.
        $this->assertCount(2, $store->series($tenant->id, 'store', 1, 'avg_basket_value'));
    }

    public function test_put_is_idempotent_per_as_of(): void
    {
        $tenant = $this->createTenant();
        $store  = app(FeatureStore::class);

        $store->put($tenant->id, 'store', 1, 'revenue', 1000, '2026-03-01');
        $store->put($tenant->id, 'store', 1, 'revenue', 1200, '2026-03-01'); // same day → update in place

        $this->assertSame(1, FeatureValue::where('tenant_id', $tenant->id)->where('feature', 'revenue')->count());
        $this->assertSame(1200.0, (float) $store->get($tenant->id, 'store', 1, 'revenue'));
    }
}
