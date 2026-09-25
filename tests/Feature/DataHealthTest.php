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

    /** W9 (WP9.8): the overall score used to average only the datasets that had data. */
    public function test_a_missing_required_dataset_scores_zero_and_is_critical(): void
    {
        $tenant = $this->createTenant();
        foreach (DataHealthService::REQUIRED as $ds) {
            $this->assertArrayHasKey($ds, DataHealthService::DEFAULTS);
        }
        $this->snap($tenant->id, DataHealthSnapshot::DATASET_SALES, 100);
        $this->snap($tenant->id, DataHealthSnapshot::DATASET_PRODUCTS, 100);
        $this->snap($tenant->id, DataHealthSnapshot::DATASET_INVENTORY, null, DataHealthSnapshot::STATUS_NO_DATA);

        $o = (new DataHealthService())->overall($tenant->id);

        $this->assertSame(DataHealthSnapshot::STATUS_CRITICAL, $o['status']);
        $this->assertEqualsWithDelta(66.7, $o['score'], 0.05, 'no inventory counts as 0, not as absent');
        $this->assertSame(['inventory'], $o['missing_required']);
    }

    public function test_missing_optional_datasets_only_warn_and_do_not_move_the_score(): void
    {
        $tenant = $this->createTenant();
        foreach (DataHealthService::REQUIRED as $ds) {
            $this->snap($tenant->id, $ds, 95);
        }
        $this->snap($tenant->id, DataHealthSnapshot::DATASET_SUPPLIERS, null, DataHealthSnapshot::STATUS_NO_DATA);
        $this->snap($tenant->id, DataHealthSnapshot::DATASET_PURCHASE_ORDERS, null, DataHealthSnapshot::STATUS_NO_DATA);

        $o = (new DataHealthService())->overall($tenant->id);

        $this->assertSame(DataHealthSnapshot::STATUS_WARNING, $o['status']);
        $this->assertEquals(95.0, $o['score']);
        $this->assertSame([], $o['missing_required']);

        $optional = (new DataHealthService())->computeDataset($tenant->id, DataHealthSnapshot::DATASET_SUPPLIERS);
        $this->assertSame('warning', $optional['warnings'][0]['level'], 'optional and absent is a warning');
        $required = (new DataHealthService())->computeDataset($tenant->id, DataHealthSnapshot::DATASET_SALES);
        $this->assertSame('critical', $required['warnings'][0]['level']);
    }

    public function test_only_required_gaps_raise_the_critical_alert(): void
    {
        $tenant = $this->createTenant();
        \App\Models\User::factory()->create(['tenant_id' => $tenant->id, 'is_tenant_admin' => true]);
        $svc = new DataHealthService();

        $svc->notifyCritical($tenant->id, collect([$this->snap($tenant->id, DataHealthSnapshot::DATASET_SUPPLIERS, null, DataHealthSnapshot::STATUS_NO_DATA)]));
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('notifications')->count());

        $svc->notifyCritical($tenant->id, collect([$this->snap($tenant->id, DataHealthSnapshot::DATASET_SALES, null, DataHealthSnapshot::STATUS_NO_DATA)]));
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('notifications')->count());
    }

    private function snap(int $tenantId, string $dataset, ?float $score, string $status = DataHealthSnapshot::STATUS_HEALTHY): DataHealthSnapshot
    {
        return DataHealthSnapshot::updateOrCreate(['tenant_id' => $tenantId, 'dataset' => $dataset],
            ['status' => $status, 'score' => $score, 'warnings' => [], 'computed_at' => now(), 'records_received' => 0, 'records_accepted' => 0, 'records_rejected' => 0]);
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
