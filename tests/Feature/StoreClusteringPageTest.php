<?php

namespace Tests\Feature;

use App\Filament\Resources\StoreClusterResource;
use App\Models\SalesTransaction;
use App\Models\Store;
use App\Models\StoreCluster;
use App\Models\Tenant;
use Filament\Facades\Filament;
use Tests\TestCase;

/**
 * Render guard for the Store Clustering page — the index builds and shows
 * clusters with their metric columns, and the create/edit forms load. Boots the
 * real Filament pages so an API or query mistake surfaces in CI, not production.
 */
class StoreClusteringPageTest extends TestCase
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

    private function store(string $code, ?string $format, ?string $region): Store
    {
        return Store::create([
            'tenant_id' => $this->tenant->id,
            'name'      => $code,
            'code'      => $code,
            'format'    => $format,
            'region'    => $region,
        ]);
    }

    public function test_index_builds_and_renders_clusters_with_metrics(): void
    {
        $a = $this->store('S1', 'Hypermarket', 'Dubai');
        $b = $this->store('S2', 'Hypermarket', 'Dubai');
        $this->store('S3', 'Express', 'Abu Dhabi');

        foreach ([[$a, 'SKU-A', 10, 100.0], [$b, 'SKU-B', 4, 40.0]] as [$store, $sku, $qty, $amt]) {
            SalesTransaction::create([
                'tenant_id'    => $this->tenant->id,
                'store_id'     => $store->id,
                'sku'          => $sku,
                'quantity'     => $qty,
                'total_amount' => $amt,
                'date'         => now()->subDays(5)->toDateString(),
            ]);
        }

        $url = StoreClusterResource::getUrl('index', ['tenant' => $this->tenant]);
        $status = $this->get($url)->baseResponse->getStatusCode();
        $this->assertLessThan(500, $status, "Store Clustering index returned HTTP $status");

        // Mounting the page should have built the recommended clusters.
        $this->assertSame(2, StoreCluster::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_create_form_renders(): void
    {
        $this->store('S1', 'Hypermarket', 'Dubai');

        $url = StoreClusterResource::getUrl('create', ['tenant' => $this->tenant]);
        $status = $this->get($url)->baseResponse->getStatusCode();
        $this->assertLessThan(500, $status, "Store Clustering create returned HTTP $status");
    }

    public function test_edit_form_renders(): void
    {
        $this->store('S1', 'Hypermarket', 'Dubai');
        app(\App\Platform\Intelligence\Clustering\ClusterService::class)->rebuild($this->tenant->id);
        $cluster = StoreCluster::where('tenant_id', $this->tenant->id)->firstOrFail();

        $url = StoreClusterResource::getUrl('edit', ['record' => $cluster, 'tenant' => $this->tenant]);
        $status = $this->get($url)->baseResponse->getStatusCode();
        $this->assertLessThan(500, $status, "Store Clustering edit returned HTTP $status");
    }
}
