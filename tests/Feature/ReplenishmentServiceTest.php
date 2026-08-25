<?php

namespace Tests\Feature;

use App\Models\InventoryLevel;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SkuProfile;
use App\Models\SkuReplenishment;
use App\Models\Store;
use App\Services\Anomaly\ReplenishmentService;
use Tests\TestCase;

/**
 * B4: replenishment targets are derived from the demand profile + observed lead
 * time, with an intermittent-demand safety stock.
 */
class ReplenishmentServiceTest extends TestCase
{
    public function test_derives_reorder_point_and_order_qty(): void
    {
        $tenant = $this->createTenant();
        $store  = Store::create(['tenant_id' => $tenant->id, 'name' => 'S1', 'code' => 'ST01']);
        Product::create(['tenant_id' => $tenant->id, 'sku' => 'RP1', 'name' => 'Item', 'selling_price' => 5, 'unit_cost' => 3]);

        // Intermittent: sells 10 units roughly every 5 days.
        SkuProfile::create([
            'tenant_id' => $tenant->id, 'sku' => 'RP1', 'store_id' => $store->id,
            'segment' => SkuProfile::SEG_INTERMITTENT,
            'mean_nonzero' => 10, 'adi' => 5, 'cv2' => 0.25,
        ]);

        // Two received POs with a 4-day lead time.
        foreach (['PO1', 'PO2'] as $n) {
            PurchaseOrder::create([
                'tenant_id' => $tenant->id, 'supplier' => 'ACME', 'sku' => 'RP1', 'po_number' => $n,
                'qty_ordered' => 100, 'qty_received' => 100,
                'order_date' => now()->subDays(20)->format('Y-m-d'),
                'received_date' => now()->subDays(16)->format('Y-m-d'),
            ]);
        }

        InventoryLevel::create([
            'tenant_id' => $tenant->id, 'store_id' => $store->id, 'sku' => 'RP1',
            'on_hand_qty' => 5, 'as_of_date' => now()->subDay()->format('Y-m-d'),
        ]);

        $count = app(ReplenishmentService::class)->computeForTenant($tenant->id);
        $this->assertSame(1, $count);

        $r = SkuReplenishment::where('tenant_id', $tenant->id)->where('sku', 'RP1')->first();
        $this->assertNotNull($r);
        $this->assertEqualsWithDelta(2.0, $r->daily_rate, 0.01);      // 10/5
        $this->assertEqualsWithDelta(4.0, $r->lead_time_days, 0.1);
        $this->assertEqualsWithDelta(23.12, $r->reorder_point, 0.5);  // d·L + Z·σ·√L
        $this->assertEqualsWithDelta(32.12, $r->suggested_order_qty, 0.5); // order-up-to − on-hand
        $this->assertSame('ACME', $r->supplier);
        $this->assertGreaterThan(0, $r->order_value);
    }

    public function test_dead_and_new_segments_are_skipped(): void
    {
        $tenant = $this->createTenant();
        $store  = Store::create(['tenant_id' => $tenant->id, 'name' => 'S1', 'code' => 'ST01']);
        Product::create(['tenant_id' => $tenant->id, 'sku' => 'DEAD1', 'name' => 'Dead', 'unit_cost' => 3]);

        SkuProfile::create([
            'tenant_id' => $tenant->id, 'sku' => 'DEAD1', 'store_id' => $store->id,
            'segment' => SkuProfile::SEG_DEAD, 'mean_nonzero' => 1, 'adi' => 90, 'cv2' => 0.1,
        ]);

        app(ReplenishmentService::class)->computeForTenant($tenant->id);

        $this->assertNull(SkuReplenishment::where('tenant_id', $tenant->id)->where('sku', 'DEAD1')->first());
    }
}
