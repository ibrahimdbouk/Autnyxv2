<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\DetectionDirtyKey;
use App\Models\InventoryLevel;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Anomaly\AnomalyDetectionService;
use App\Services\Detection\DirtyKeyRecorder;
use App\Services\Detection\RunScope;
use Tests\TestCase;

/**
 * Incremental detection — Slice 2. An incremental run scans only the scoped
 * (dirty + still-open) SKUs; a full run is unchanged. Recovery evaluability
 * follows the scope for free (it derives from the scoped primed maps).
 */
class IncrementalDetectionTest extends TestCase
{
    private function negativeStock(Tenant $t, Store $store, string $sku): void
    {
        InventoryLevel::factory()->create([
            'tenant_id'   => $t->id,
            'store_id'    => $store->id,
            'sku'         => $sku,
            'on_hand_qty' => -5,        // negative_inventory: latest on-hand < 0
            'as_of_date'  => now()->toDateString(),
        ]);
    }

    public function test_incremental_run_flags_only_scoped_skus(): void
    {
        $t     = $this->createTenant();
        $store = Store::create(['tenant_id' => $t->id, 'name' => 'S1', 'code' => 'ST1']);

        $this->negativeStock($t, $store, 'DIRTY');
        $this->negativeStock($t, $store, 'OTHER');

        app(DirtyKeyRecorder::class)->record($t->id, [['store_id' => $store->id, 'sku' => 'DIRTY']]);

        $scope = RunScope::forTenant($t->id, 20000);
        $this->assertNotNull($scope);
        app(AnomalyDetectionService::class)->runForTenant($t->id, $scope);

        $this->assertDatabaseHas('anomalies', ['tenant_id' => $t->id, 'rule_type' => 'negative_inventory', 'sku' => 'DIRTY']);
        $this->assertDatabaseMissing('anomalies', ['tenant_id' => $t->id, 'rule_type' => 'negative_inventory', 'sku' => 'OTHER']);
    }

    public function test_full_run_flags_every_sku(): void
    {
        $t     = $this->createTenant();
        $store = Store::create(['tenant_id' => $t->id, 'name' => 'S1', 'code' => 'ST1']);

        $this->negativeStock($t, $store, 'DIRTY');
        $this->negativeStock($t, $store, 'OTHER');

        app(AnomalyDetectionService::class)->runForTenant($t->id); // full — no scope

        $this->assertDatabaseHas('anomalies', ['tenant_id' => $t->id, 'rule_type' => 'negative_inventory', 'sku' => 'DIRTY']);
        $this->assertDatabaseHas('anomalies', ['tenant_id' => $t->id, 'rule_type' => 'negative_inventory', 'sku' => 'OTHER']);
    }

    public function test_incremental_scopes_the_raw_shrinkage_rule(): void
    {
        $t     = $this->createTenant();
        $store = Store::create(['tenant_id' => $t->id, 'name' => 'S1', 'code' => 'ST1']);

        // Two SKUs each with an unexplained drop (100 → 50, no sales) — a 50% shrink.
        foreach (['SHRINK', 'OTHERSHRINK'] as $sku) {
            InventoryLevel::factory()->create([
                'tenant_id' => $t->id, 'store_id' => $store->id, 'sku' => $sku, 'location' => 'L1',
                'on_hand_qty' => 100, 'as_of_date' => now()->subDays(10)->toDateString(),
            ]);
            InventoryLevel::factory()->create([
                'tenant_id' => $t->id, 'store_id' => $store->id, 'sku' => $sku, 'location' => 'L1',
                'on_hand_qty' => 50, 'as_of_date' => now()->toDateString(),
            ]);
        }

        app(DirtyKeyRecorder::class)->record($t->id, [['store_id' => $store->id, 'sku' => 'SHRINK']]);

        $scope = RunScope::forTenant($t->id, 20000);
        app(AnomalyDetectionService::class)->runForTenant($t->id, $scope);

        $this->assertDatabaseHas('anomalies', ['tenant_id' => $t->id, 'rule_type' => 'inventory_shrinkage', 'sku' => 'SHRINK']);
        $this->assertDatabaseMissing('anomalies', ['tenant_id' => $t->id, 'rule_type' => 'inventory_shrinkage', 'sku' => 'OTHERSHRINK']);
    }

    public function test_scope_union_includes_open_anomaly_subjects(): void
    {
        $t     = $this->createTenant();
        $store = Store::create(['tenant_id' => $t->id, 'name' => 'S1', 'code' => 'ST1']);

        app(DirtyKeyRecorder::class)->record($t->id, [['store_id' => $store->id, 'sku' => 'CHANGED']]);
        Anomaly::factory()->create(['tenant_id' => $t->id, 'sku' => 'OPENSUBJ', 'lifecycle_state' => Anomaly::LIFECYCLE_OPEN]);

        $scope = RunScope::forTenant($t->id, 20000);

        $this->assertContains('CHANGED', $scope->skus());
        $this->assertContains('OPENSUBJ', $scope->skus(), 'still-open subjects are re-scanned so recovery can advance');
    }

    public function test_scope_is_empty_when_nothing_dirty_or_open(): void
    {
        $t = $this->createTenant();
        $this->assertTrue(RunScope::forTenant($t->id, 20000)->isEmpty());
    }

    public function test_command_consumes_dirty_keys_and_stamps_watermark(): void
    {
        $t     = $this->createTenant();
        $store = Store::create(['tenant_id' => $t->id, 'name' => 'S1', 'code' => 'ST1']);
        $this->negativeStock($t, $store, 'DIRTY');
        app(DirtyKeyRecorder::class)->record($t->id, [['store_id' => $store->id, 'sku' => 'DIRTY']]);

        $this->artisan('anomalies:detect', ['--tenant' => $t->id, '--mode' => 'incremental'])
            ->assertSuccessful();

        $this->assertSame(0, DetectionDirtyKey::where('tenant_id', $t->id)->count(), 'consumed after a successful run');
        $this->assertNotNull($t->fresh()->last_detection_at);
    }
}
