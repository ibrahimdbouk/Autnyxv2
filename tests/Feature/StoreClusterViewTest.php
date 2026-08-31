<?php

namespace Tests\Feature;

use App\Filament\Resources\StoreClusterResource;
use App\Filament\Resources\StoreResource;
use App\Models\Store;
use App\Models\StoreCluster;
use App\Models\StoreFeature;
use App\Models\Tenant;
use Filament\Facades\Filament;
use Tests\TestCase;

/**
 * Render guards for the clustering frontend: the store profile columns on the
 * Stores page, and the cluster drill-down (attribute + demand + a store with no
 * profile). Boots the real pages so a template or query mistake surfaces in CI.
 */
class StoreClusterViewTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->actingAsTenantAdmin($this->tenant);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->tenant);
    }

    private function store(string $code, ?array $feature = null): Store
    {
        $store = Store::create([
            'tenant_id' => $this->tenant->id, 'name' => $code, 'code' => $code,
            'city' => 'Dubai', 'format' => 'Hypermarket', 'region' => 'Dubai',
        ]);
        if ($feature !== null) {
            StoreFeature::create(array_merge([
                'tenant_id' => $this->tenant->id, 'store_id' => $store->id, 'window_days' => 90, 'computed_at' => now(),
            ], $feature));
        }

        return $store;
    }

    private function httpStatus(string $url): int
    {
        return $this->get($url)->baseResponse->getStatusCode();
    }

    public function test_stores_index_renders_with_profile_columns(): void
    {
        $this->store('S1', [
            'revenue' => 90000, 'avg_basket_value' => 500, 'avg_selling_price' => 200,
            'descriptor' => 'Large-format · premium · high-basket',
            'size_tier' => 'large', 'price_tier' => 'premium', 'basket_tier' => 'high', 'dominant_segment' => 'smooth',
        ]);
        $this->store('S2'); // no profile → placeholder path

        $status = $this->httpStatus(StoreResource::getUrl('index', ['tenant' => $this->tenant]));
        $this->assertLessThan(500, $status, "Stores index returned HTTP $status");
    }

    public function test_attribute_cluster_drilldown_renders(): void
    {
        $a = $this->store('A', [
            'revenue' => 90000, 'avg_basket_value' => 500, 'descriptor' => 'Large-format · premium', 'price_tier' => 'premium', 'dominant_segment' => 'smooth',
        ]);
        $b = $this->store('B'); // member without a profile

        $cluster = StoreCluster::create([
            'tenant_id' => $this->tenant->id, 'method' => 'attribute', 'objective' => 'general',
            'key' => 'hypermarket__dubai', 'label' => 'Hypermarket · Dubai',
            'params' => ['format' => 'Hypermarket', 'region' => 'Dubai'],
        ]);
        $cluster->stores()->attach([$a->id, $b->id]);

        $status = $this->httpStatus(StoreClusterResource::getUrl('detail', ['record' => $cluster, 'tenant' => $this->tenant]));
        $this->assertLessThan(500, $status, "Attribute cluster view returned HTTP $status");
    }

    public function test_demand_cluster_drilldown_renders(): void
    {
        $a = $this->store('A', ['revenue' => 90000, 'avg_basket_value' => 500, 'price_tier' => 'premium', 'descriptor' => 'Large · premium']);

        $cluster = StoreCluster::create([
            'tenant_id' => $this->tenant->id, 'method' => 'demand', 'objective' => 'general',
            'key' => 'demand-1', 'label' => 'Large-format · premium · high-basket',
            'params' => [
                'store_count' => 1, 'size_tier' => 'large', 'price_tier' => 'premium', 'basket_tier' => 'high',
                'dominant_segment' => 'smooth', 'avg_basket_value' => 500, 'avg_selling_price' => 200, 'avg_daily_revenue' => 1000,
            ],
        ]);
        $cluster->stores()->attach([$a->id]);

        $status = $this->httpStatus(StoreClusterResource::getUrl('detail', ['record' => $cluster, 'tenant' => $this->tenant]));
        $this->assertLessThan(500, $status, "Demand cluster view returned HTTP $status");
    }
}
