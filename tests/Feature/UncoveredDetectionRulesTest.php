<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\InventoryLevel;
use App\Models\PurchaseOrder;
use App\Models\SalesDaily;
use App\Models\SalesTransaction;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Anomaly\AnomalyDetectionService;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * End-to-end firing tests for the seven detection rules that previously had NO
 * coverage anywhere in the suite: return_rate_spike, channel_mix_shift,
 * phantom_inventory, multi_location_imbalance, reorder_point_staleness,
 * supplier_lead_time_drift, location_proliferation.
 *
 * Each test seeds the minimum fixture that should trip the rule, runs the full
 * detection pass (rules auto-seed enabled inside runForTenant), and asserts the
 * anomaly is flagged — and, where cheap, that a clean sibling stays silent.
 */
class UncoveredDetectionRulesTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
    }

    private function fireDetection(): void
    {
        app(AnomalyDetectionService::class)->runForTenant($this->tenant->id);
    }

    private function flagged(string $ruleType, ?string $sku = null): ?Anomaly
    {
        $q = Anomaly::where('tenant_id', $this->tenant->id)->where('rule_type', $ruleType);
        if ($sku !== null) {
            $q->where('sku', $sku);
        }
        return $q->first();
    }

    private function day(int $daysAgo): string
    {
        return Carbon::today()->subDays($daysAgo)->format('Y-m-d');
    }

    public function test_return_rate_spike_flags_a_high_return_sku_and_ignores_a_clean_one(): void
    {
        // High returns: 100 sold, 30 returned → 30/130 ≈ 23% ≥ 15% threshold.
        SalesTransaction::create(['tenant_id' => $this->tenant->id, 'sku' => 'RET-BAD', 'date' => $this->day(3), 'quantity' => 100]);
        SalesTransaction::create(['tenant_id' => $this->tenant->id, 'sku' => 'RET-BAD', 'date' => $this->day(3), 'quantity' => -30]);

        // Clean: 100 sold, 2 returned → ~2% < 15%.
        SalesTransaction::create(['tenant_id' => $this->tenant->id, 'sku' => 'RET-OK', 'date' => $this->day(3), 'quantity' => 100]);
        SalesTransaction::create(['tenant_id' => $this->tenant->id, 'sku' => 'RET-OK', 'date' => $this->day(3), 'quantity' => -2]);

        $this->fireDetection();

        $this->assertNotNull($this->flagged('return_rate_spike', 'RET-BAD'), 'a 23% return rate should flag');
        $this->assertNull($this->flagged('return_rate_spike', 'RET-OK'), 'a ~2% return rate should not flag');
    }

    public function test_channel_mix_shift_flags_a_location_that_halves_its_sales_share(): void
    {
        // Prior window (~45d ago): A and B each 1000 units → 50% / 50%.
        SalesTransaction::create(['tenant_id' => $this->tenant->id, 'sku' => 'MIX', 'location' => 'Store A', 'date' => $this->day(45), 'quantity' => 1000]);
        SalesTransaction::create(['tenant_id' => $this->tenant->id, 'sku' => 'MIX', 'location' => 'Store B', 'date' => $this->day(45), 'quantity' => 1000]);

        // Recent window (~5d ago): A 1500, B 500 → 75% / 25%. B's share halves.
        SalesTransaction::create(['tenant_id' => $this->tenant->id, 'sku' => 'MIX', 'location' => 'Store A', 'date' => $this->day(5), 'quantity' => 1500]);
        SalesTransaction::create(['tenant_id' => $this->tenant->id, 'sku' => 'MIX', 'location' => 'Store B', 'date' => $this->day(5), 'quantity' => 500]);

        $this->fireDetection();

        $this->assertNotNull(
            $this->flagged('channel_mix_shift'),
            'a location whose sales share moved 50% relative should flag a channel mix shift'
        );
    }

    public function test_phantom_inventory_flags_stock_with_no_demand_and_ignores_a_seller(): void
    {
        $store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Store One', 'code' => 'ST01']);

        // Phantom: 50 units on hand, zero recent sales.
        InventoryLevel::create([
            'tenant_id' => $this->tenant->id, 'store_id' => $store->id, 'sku' => 'PH-DEAD',
            'location' => 'Store One', 'on_hand_qty' => 50, 'as_of_date' => $this->day(2),
        ]);

        // Not phantom: 50 units on hand, but it is actively selling. Demand is
        // read from sales_daily (not sales_transactions), so seed it there.
        InventoryLevel::create([
            'tenant_id' => $this->tenant->id, 'store_id' => $store->id, 'sku' => 'PH-SELL',
            'location' => 'Store One', 'on_hand_qty' => 50, 'as_of_date' => $this->day(2),
        ]);
        for ($i = 1; $i <= 10; $i++) {
            SalesDaily::create([
                'tenant_id' => $this->tenant->id, 'store_id' => $store->id, 'sku' => 'PH-SELL',
                'date' => $this->day($i), 'units_sold' => 5, 'revenue' => 500, 'transaction_count' => 2,
            ]);
        }

        $this->fireDetection();

        $this->assertNotNull($this->flagged('phantom_inventory', 'PH-DEAD'), 'stock with no demand should be phantom inventory');
        $this->assertNull($this->flagged('phantom_inventory', 'PH-SELL'), 'a selling SKU should not be phantom inventory');
    }

    public function test_multi_location_imbalance_flags_a_sku_stocked_out_in_one_store_and_piled_up_in_another(): void
    {
        $a = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Store A', 'code' => 'ST-A']);
        $b = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Store B', 'code' => 'ST-B']);

        // IMB-BAD: out at A (0 ≤ reorder 10), piled up at B (100 > reorder*2).
        InventoryLevel::create(['tenant_id' => $this->tenant->id, 'store_id' => $a->id, 'sku' => 'IMB-BAD', 'location' => 'Store A', 'on_hand_qty' => 0,   'reorder_point' => 10, 'as_of_date' => $this->day(1)]);
        InventoryLevel::create(['tenant_id' => $this->tenant->id, 'store_id' => $b->id, 'sku' => 'IMB-BAD', 'location' => 'Store B', 'on_hand_qty' => 100, 'reorder_point' => 10, 'as_of_date' => $this->day(1)]);

        // IMB-OK: healthy at both stores (neither out) → no imbalance.
        InventoryLevel::create(['tenant_id' => $this->tenant->id, 'store_id' => $a->id, 'sku' => 'IMB-OK', 'location' => 'Store A', 'on_hand_qty' => 50, 'reorder_point' => 10, 'as_of_date' => $this->day(1)]);
        InventoryLevel::create(['tenant_id' => $this->tenant->id, 'store_id' => $b->id, 'sku' => 'IMB-OK', 'location' => 'Store B', 'on_hand_qty' => 50, 'reorder_point' => 10, 'as_of_date' => $this->day(1)]);

        $this->fireDetection();

        $this->assertNotNull($this->flagged('multi_location_imbalance', 'IMB-BAD'), 'stocked out in one store, surplus in another should flag');
        $this->assertNull($this->flagged('multi_location_imbalance', 'IMB-OK'), 'balanced stock across stores should not flag');
    }

    public function test_reorder_point_staleness_flags_an_old_reorder_point_on_an_active_sku(): void
    {
        $store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Store One', 'code' => 'ST01']);

        // Stale: reorder point last set ~120d ago (> 90d), SKU still selling.
        InventoryLevel::create([
            'tenant_id' => $this->tenant->id, 'store_id' => $store->id, 'sku' => 'RP-STALE',
            'location' => 'Store One', 'on_hand_qty' => 50, 'reorder_point' => 10, 'as_of_date' => $this->day(120),
        ]);
        SalesTransaction::create(['tenant_id' => $this->tenant->id, 'store_id' => $store->id, 'sku' => 'RP-STALE', 'date' => $this->day(5), 'quantity' => 8]);

        // Fresh: reorder point set 2 days ago → not stale even though it sells.
        InventoryLevel::create([
            'tenant_id' => $this->tenant->id, 'store_id' => $store->id, 'sku' => 'RP-FRESH',
            'location' => 'Store One', 'on_hand_qty' => 50, 'reorder_point' => 10, 'as_of_date' => $this->day(2),
        ]);
        SalesTransaction::create(['tenant_id' => $this->tenant->id, 'store_id' => $store->id, 'sku' => 'RP-FRESH', 'date' => $this->day(5), 'quantity' => 8]);

        $this->fireDetection();

        $this->assertNotNull($this->flagged('reorder_point_staleness', 'RP-STALE'), 'a 120-day-old reorder point on an active SKU should flag');
        $this->assertNull($this->flagged('reorder_point_staleness', 'RP-FRESH'), 'a fresh reorder point should not flag');
    }

    public function test_supplier_lead_time_drift_flags_a_supplier_whose_lead_time_grew(): void
    {
        // Acme: prior lead ~10 days, recent lead ~20 days → +100% drift ≥ 30%.
        PurchaseOrder::create(['tenant_id' => $this->tenant->id, 'po_number' => 'PO-A1', 'supplier' => 'Acme', 'sku' => 'PO-1', 'qty_ordered' => 100, 'order_date' => $this->day(150), 'received_date' => $this->day(140)]);
        PurchaseOrder::create(['tenant_id' => $this->tenant->id, 'po_number' => 'PO-A2', 'supplier' => 'Acme', 'sku' => 'PO-1', 'qty_ordered' => 100, 'order_date' => $this->day(30),  'received_date' => $this->day(10)]);

        // Stable: prior lead ~10 days, recent lead ~10 days → no drift.
        PurchaseOrder::create(['tenant_id' => $this->tenant->id, 'po_number' => 'PO-S1', 'supplier' => 'Stable', 'sku' => 'PO-2', 'qty_ordered' => 100, 'order_date' => $this->day(150), 'received_date' => $this->day(140)]);
        PurchaseOrder::create(['tenant_id' => $this->tenant->id, 'po_number' => 'PO-S2', 'supplier' => 'Stable', 'sku' => 'PO-2', 'qty_ordered' => 100, 'order_date' => $this->day(30),  'received_date' => $this->day(20)]);

        $this->fireDetection();

        $drift = Anomaly::where('tenant_id', $this->tenant->id)
            ->where('rule_type', 'supplier_lead_time_drift')
            ->get();

        $this->assertTrue($drift->contains(fn ($a) => ($a->context['supplier'] ?? null) === 'Acme'), 'Acme lead-time drift should flag');
        $this->assertFalse($drift->contains(fn ($a) => ($a->context['supplier'] ?? null) === 'Stable'), 'a stable supplier should not flag');
    }

    public function test_location_proliferation_flags_a_bare_auto_created_store(): void
    {
        // Bare store: no code/city/address, freshly created (within the 7-day window).
        Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Unknown Import Location']);

        // Enriched store: has code/city/address → not a proliferation signal.
        Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Real Store', 'code' => 'ST99', 'city' => 'Dubai', 'address' => '123 Sheikh Zayed Rd']);

        $this->fireDetection();

        $flags = Anomaly::where('tenant_id', $this->tenant->id)->where('rule_type', 'location_proliferation')->get();
        $this->assertCount(1, $flags, 'exactly the bare auto-created store should flag location proliferation');
        $this->assertStringContainsString('Unknown Import Location', (string) $flags->first()->description);
    }
}
