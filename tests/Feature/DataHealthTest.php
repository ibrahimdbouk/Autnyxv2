<?php

namespace Tests\Feature;

use App\Models\DataHealthSnapshot;
use App\Models\Product;
use App\Models\SalesTransaction;
use App\Services\DataHealth\DataHealthService;
use Tests\TestCase;

class DataHealthTest extends TestCase
{
    public function test_sales_dataset_is_scored_and_orphans_detected(): void
    {
        $tenant = $this->createTenant();

        Product::create(['tenant_id' => $tenant->id, 'sku' => 'SKU-A', 'name' => 'A', 'unit_cost' => 1, 'selling_price' => 2]);

        // Fresh, clean sales for a known SKU
        SalesTransaction::factory()->count(5)->create([
            'tenant_id' => $tenant->id, 'sku' => 'SKU-A', 'date' => now()->toDateString(),
        ]);
        // One orphan sale referencing a SKU with no product
        SalesTransaction::factory()->create([
            'tenant_id' => $tenant->id, 'sku' => 'SKU-ORPHAN', 'date' => now()->toDateString(),
        ]);

        $data = (new DataHealthService())->computeDataset($tenant->id, DataHealthSnapshot::DATASET_SALES);

        $this->assertSame(6, $data['records_received']);
        $this->assertNotSame(DataHealthSnapshot::STATUS_NO_DATA, $data['status']);
        $this->assertSame(1, $data['metrics']['orphan_count']);
        $this->assertNotNull($data['score']);
    }

    public function test_dataset_with_no_data_reports_no_data(): void
    {
        $tenant = $this->createTenant();

        $data = (new DataHealthService())->computeDataset($tenant->id, DataHealthSnapshot::DATASET_INVENTORY);

        $this->assertSame(DataHealthSnapshot::STATUS_NO_DATA, $data['status']);
        $this->assertNull($data['score']);
    }

    public function test_snapshots_are_tenant_scoped(): void
    {
        $a = $this->createTenant();
        $b = $this->createTenant();

        Product::create(['tenant_id' => $a->id, 'sku' => 'SKU-A', 'name' => 'A', 'unit_cost' => 1, 'selling_price' => 2]);
        SalesTransaction::factory()->create(['tenant_id' => $a->id, 'sku' => 'SKU-A', 'date' => now()->toDateString()]);

        (new DataHealthService())->computeForTenant($a->id);

        $this->assertTrue(DataHealthSnapshot::where('tenant_id', $a->id)->exists());
        $this->assertFalse(DataHealthSnapshot::where('tenant_id', $b->id)->exists());
    }
}
