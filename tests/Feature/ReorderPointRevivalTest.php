<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\InventoryLevel;
use App\Models\Product;
use App\Models\SalesDaily;
use App\Models\SkuReplenishment;
use App\Models\Store;
use App\Services\Anomaly\AnomalyDetectionService;
use Tests\TestCase;

/**
 * B4: a derived reorder point revives rules that die when the tenant supplies no
 * reorder point of its own — and stockout alerts carry a suggested order qty.
 * (No sku_profiles here, so best-fit gating is inactive and these rules run.)
 */
class ReorderPointRevivalTest extends TestCase
{
    public function test_derived_reorder_point_revives_rules_and_suggests_order(): void
    {
        $tenant = $this->createTenant();
        $store  = Store::create(['tenant_id' => $tenant->id, 'name' => 'S1', 'code' => 'ST01']);
        Product::create(['tenant_id' => $tenant->id, 'sku' => 'REV1', 'name' => 'Item', 'selling_price' => 100, 'unit_cost' => 50]);

        // Derived replenishment: reorder point 20, suggest 30 units from ACME.
        SkuReplenishment::create([
            'tenant_id' => $tenant->id, 'sku' => 'REV1', 'store_id' => $store->id,
            'supplier' => 'ACME', 'segment' => 'intermittent',
            'daily_rate' => 2, 'lead_time_days' => 4, 'safety_stock' => 10,
            'reorder_point' => 20, 'order_up_to' => 35,
            'on_hand' => 5, 'suggested_order_qty' => 30, 'unit_cost' => 50,
            'order_value' => 1500, 'service_level' => 95, 'computed_at' => now(),
        ]);

        // On hand 5, and crucially NO tenant reorder point on the inventory row.
        InventoryLevel::create([
            'tenant_id' => $tenant->id, 'store_id' => $store->id, 'sku' => 'REV1',
            'on_hand_qty' => 5, 'reorder_point' => null,
            'as_of_date' => now()->subDay()->format('Y-m-d'),
        ]);
        // It still sells, so a stockout means real lost sales.
        SalesDaily::create([
            'tenant_id' => $tenant->id, 'store_id' => $store->id, 'sku' => 'REV1',
            'date' => now()->subDays(2)->format('Y-m-d'),
            'units_sold' => 20, 'revenue' => 2000, 'transaction_count' => 1,
        ]);

        app(AnomalyDetectionService::class)->runForTenant($tenant->id);

        // Stockout fires ONLY because on-hand (5) ≤ the derived reorder point (20).
        $stockout = Anomaly::where('tenant_id', $tenant->id)->where('rule_type', 'stockout_risk')->where('sku', 'REV1')->first();
        $this->assertNotNull($stockout, 'derived reorder point should trigger stockout risk');
        $this->assertStringContainsString('Suggest ordering ~30 units from ACME', $stockout->description);
        $this->assertSame(30.0, (float) ($stockout->context['suggested_order_qty'] ?? 0));

        // Safety-stock breach fires because on-hand (5) < 50% of the derived reorder point (10).
        $this->assertNotNull(
            Anomaly::where('tenant_id', $tenant->id)->where('rule_type', 'safety_stock_breach')->where('sku', 'REV1')->first(),
            'derived reorder point should revive safety_stock_breach'
        );
    }
}
