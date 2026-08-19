<?php

namespace Tests\Unit;

use App\Models\Anomaly;
use App\Models\AnomalySetting;
use App\Models\InventoryLevel;
use App\Models\SalesTransaction;
use App\Services\Anomaly\AnomalyDetectionService;
use App\Services\Anomaly\BaselineCalculatorService;
use Carbon\Carbon;
use Tests\TestCase;

class AnomalyDetectionTest extends TestCase
{
    private AnomalyDetectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AnomalyDetectionService(
            new BaselineCalculatorService()
        );
    }

    /**
     * When recent sales quantity is far below the historical average, a sales_drop anomaly is created.
     *
     * Detection logic (no baseline): changePct = ((avg - recent) / avg) * 100 >= 30%
     * Historical window: 8–35 days ago; recent window: last 7 days.
     */
    public function test_sales_drop_is_flagged_when_recent_qty_below_threshold(): void
    {
        $tenant = $this->createTenant();

        // Historical transactions: 8–35 days ago, high volume (4 weeks × 100 units ≈ 400 total)
        for ($daysAgo = 8; $daysAgo <= 35; $daysAgo++) {
            SalesTransaction::factory()->create([
                'tenant_id' => $tenant->id,
                'sku'       => 'SKU-DROP',
                'date'      => Carbon::today()->subDays($daysAgo)->toDateString(),
                'quantity'  => 100,
            ]);
        }

        // Recent transactions: last 7 days, very low volume (1 unit total → 99% drop)
        SalesTransaction::factory()->create([
            'tenant_id' => $tenant->id,
            'sku'       => 'SKU-DROP',
            'date'      => Carbon::today()->toDateString(),
            'quantity'  => 1,
        ]);

        // Disable all rules except sales_drop to keep the test focused
        AnomalySetting::seedForTenant($tenant->id);
        AnomalySetting::where('tenant_id', $tenant->id)
            ->where('rule_type', '!=', 'sales_drop')
            ->update(['enabled' => false]);

        $this->service->runForTenant($tenant->id);

        $this->assertDatabaseHas('anomalies', [
            'tenant_id' => $tenant->id,
            'rule_type' => 'sales_drop',
            'sku'       => 'SKU-DROP',
        ]);
    }

    /**
     * When multiple sales transactions share the same transaction_id, a duplicate anomaly is created.
     */
    public function test_duplicate_transaction_ids_creates_anomaly(): void
    {
        $tenant = $this->createTenant();
        $dupId  = 'TXN-DUPE-001';

        SalesTransaction::factory()->count(3)->create([
            'tenant_id'      => $tenant->id,
            'sku'            => 'SKU-DUPE',
            'transaction_id' => $dupId,
        ]);

        AnomalySetting::seedForTenant($tenant->id);
        AnomalySetting::where('tenant_id', $tenant->id)
            ->where('rule_type', '!=', 'duplicate_transaction_ids')
            ->update(['enabled' => false]);

        $this->service->runForTenant($tenant->id);

        $this->assertDatabaseHas('anomalies', [
            'tenant_id' => $tenant->id,
            'rule_type' => 'duplicate_transaction_ids',
        ]);
    }

    /**
     * An inventory level at or below the reorder point triggers a stockout_risk anomaly.
     *
     * Detection: on_hand_qty <= reorder_point AND reorder_point > 0
     */
    public function test_stockout_risk_detected_when_on_hand_is_zero(): void
    {
        $tenant = $this->createTenant();

        InventoryLevel::factory()->create([
            'tenant_id'     => $tenant->id,
            'sku'           => 'SKU-OUT',
            'on_hand_qty'   => 0,
            'reorder_point' => 50,
        ]);

        AnomalySetting::seedForTenant($tenant->id);
        AnomalySetting::where('tenant_id', $tenant->id)
            ->where('rule_type', '!=', 'stockout_risk')
            ->update(['enabled' => false]);

        $this->service->runForTenant($tenant->id);

        $this->assertDatabaseHas('anomalies', [
            'tenant_id' => $tenant->id,
            'rule_type' => 'stockout_risk',
            'sku'       => 'SKU-OUT',
        ]);
    }

    /**
     * A SKU with inventory on hand but no sales in the lookback window triggers a dead_stock anomaly.
     *
     * Default threshold: 30 days (from AnomalySetting defaults).
     */
    public function test_dead_stock_detected_when_no_sales_for_lookback_period(): void
    {
        $tenant = $this->createTenant();

        // Inventory exists
        InventoryLevel::factory()->create([
            'tenant_id'   => $tenant->id,
            'sku'         => 'SKU-DEAD',
            'on_hand_qty' => 200,
        ]);

        // Last sale was more than 90 days ago (well outside any threshold window)
        SalesTransaction::factory()->create([
            'tenant_id' => $tenant->id,
            'sku'       => 'SKU-DEAD',
            'date'      => Carbon::today()->subDays(91)->toDateString(),
            'quantity'  => 10,
        ]);

        AnomalySetting::seedForTenant($tenant->id);
        AnomalySetting::where('tenant_id', $tenant->id)
            ->where('rule_type', '!=', 'dead_stock')
            ->update(['enabled' => false]);

        $this->service->runForTenant($tenant->id);

        $this->assertDatabaseHas('anomalies', [
            'tenant_id' => $tenant->id,
            'rule_type' => 'dead_stock',
            'sku'       => 'SKU-DEAD',
        ]);
    }

    /**
     * Detection runs for tenant A must not create anomalies attributed to tenant B,
     * even when tenant B has identical data patterns.
     */
    public function test_detection_does_not_bleed_across_tenants(): void
    {
        $tenantA = $this->createTenant();
        $tenantB = $this->createTenant();

        // Give tenant B a stockout so detection would fire if run for B
        InventoryLevel::factory()->create([
            'tenant_id'     => $tenantB->id,
            'sku'           => 'SKU-B-ONLY',
            'on_hand_qty'   => 0,
            'reorder_point' => 50,
        ]);

        // Seed and run detection ONLY for tenant A
        AnomalySetting::seedForTenant($tenantA->id);

        $this->service->runForTenant($tenantA->id);

        // No anomalies should be created for tenant B
        $this->assertDatabaseMissing('anomalies', [
            'tenant_id' => $tenantB->id,
        ]);
    }
}
