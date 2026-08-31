<?php

namespace Tests\Feature;

use App\Filament\Pages\ClusteringMethod;
use App\Models\Store;
use App\Models\StoreFeature;
use App\Models\Tenant;
use App\Platform\Intelligence\Clustering\ClusterService;
use Filament\Facades\Filament;
use Tests\TestCase;

/**
 * Render guard for the Clustering Method (compare + switch) page, with and
 * without store profiles, and that switching flips the tenant's active strategy.
 */
class ClusteringMethodPageTest extends TestCase
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

    private function pageStatus(): int
    {
        return $this->get(ClusteringMethod::getUrl(['tenant' => $this->tenant]))->baseResponse->getStatusCode();
    }

    public function test_renders_without_profiles(): void
    {
        Store::create(['tenant_id' => $this->tenant->id, 'name' => 'A', 'code' => 'A', 'format' => 'Hypermarket', 'region' => 'Dubai']);

        $this->assertLessThan(500, $this->pageStatus());
    }

    public function test_renders_with_profiles_and_a_split(): void
    {
        foreach ([[500, 200], [520, 210], [80, 20], [85, 22]] as $i => [$basket, $price]) {
            $store = Store::create(['tenant_id' => $this->tenant->id, 'name' => "S$i", 'code' => "S$i", 'format' => 'Hypermarket', 'region' => 'Dubai']);
            StoreFeature::create([
                'tenant_id' => $this->tenant->id, 'store_id' => $store->id, 'window_days' => 90, 'computed_at' => now(),
                'revenue' => 50000, 'active_skus' => 1000, 'basket_count' => 100,
                'avg_daily_revenue' => 500, 'avg_basket_value' => $basket, 'avg_selling_price' => $price,
                'sku_productivity' => 250, 'promo_share' => 0.1, 'growth_ratio' => 1.0,
            ]);
        }

        $this->assertLessThan(500, $this->pageStatus());
    }

    public function test_switching_flips_the_active_strategy(): void
    {
        $svc = app(ClusterService::class);
        $this->assertSame('attribute', $svc->activeMethod($this->tenant->id));

        $svc->setActiveMethod($this->tenant->id, 'demand');

        $this->assertSame('demand', $svc->activeMethod($this->tenant->id));
    }
}
