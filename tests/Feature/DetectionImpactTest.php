<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\InventoryLevel;
use App\Models\Investigation;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesDaily;
use App\Models\SalesTransaction;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Anomaly\AnomalyDetectionService;
use App\Services\Anomaly\InvestigationCorrelationService;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Locks in the revenue-impact scoring + minimum-revenue floor on the noisy
 * sales rules: a high-value SKU's spike is flagged and carries a revenue
 * impact that flows through to the investigation; a penny SKU's identical
 * percentage spike is floored out as noise.
 */
class DetectionImpactTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
    }

    private function sale(string $sku, string $date, float $qty): void
    {
        SalesTransaction::create([
            'tenant_id' => $this->tenant->id,
            'sku'       => $sku,
            'date'      => $date,
            'quantity'  => $qty,
        ]);
    }

    public function test_high_value_spike_flags_with_impact_penny_spike_is_floored(): void
    {
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'SKU-BIG',  'name' => 'Big',  'selling_price' => 100]);
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'SKU-TINY', 'name' => 'Tiny', 'selling_price' => 1]);

        $hist   = Carbon::today()->subDays(10)->format('Y-m-d'); // in the historical window
        $recent = Carbon::today()->subDays(1)->format('Y-m-d');  // in the recent window

        // Both SKUs: baseline ~5 units/period, recent 50 units → a ~900% spike.
        foreach (['SKU-BIG', 'SKU-TINY'] as $sku) {
            $this->sale($sku, $hist, 20);   // historical total (avg 5 across 4 periods)
            $this->sale($sku, $recent, 50); // recent surge
        }

        app(AnomalyDetectionService::class)->runForTenant($this->tenant->id);

        $big = Anomaly::where('tenant_id', $this->tenant->id)
            ->where('rule_type', 'sales_spike')
            ->where('sku', 'SKU-BIG')
            ->first();

        $this->assertNotNull($big, 'high-value spike should be flagged');
        $this->assertGreaterThanOrEqual(500, (float) ($big->context['revenue_impact'] ?? 0));

        $tiny = Anomaly::where('tenant_id', $this->tenant->id)
            ->where('rule_type', 'sales_spike')
            ->where('sku', 'SKU-TINY')
            ->first();

        $this->assertNull($tiny, 'penny-value spike should be floored out as noise');

        // Impact flows through to the investigation so the list can rank by money.
        app(InvestigationCorrelationService::class)->correlateForTenant($this->tenant->id);

        $inv = Investigation::where('tenant_id', $this->tenant->id)
            ->where('primary_sku', 'SKU-BIG')
            ->first();

        $this->assertNotNull($inv);
        $this->assertGreaterThan(0, (float) $inv->revenue_at_risk);
    }

    public function test_demand_aware_stockout_flags_selling_sku_at_zero_stock(): void
    {
        $store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Store One', 'code' => 'ST01']);
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'SK-OUT',  'name' => 'Seller', 'selling_price' => 100]);
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'SK-DEAD', 'name' => 'Dead',   'selling_price' => 100]);

        // Both currently at zero stock (latest snapshot), no reorder point at all.
        foreach (['SK-OUT', 'SK-DEAD'] as $sku) {
            InventoryLevel::create([
                'tenant_id'   => $this->tenant->id,
                'store_id'    => $store->id,
                'sku'         => $sku,
                'on_hand_qty' => 0,
                'as_of_date'  => now()->subDays(2)->format('Y-m-d'),
            ]);
        }

        // SK-OUT sells briskly (real demand); SK-DEAD has none.
        for ($i = 1; $i <= 10; $i++) {
            SalesDaily::create([
                'tenant_id'         => $this->tenant->id,
                'store_id'          => $store->id,
                'sku'               => 'SK-OUT',
                'date'              => now()->subDays($i)->format('Y-m-d'),
                'units_sold'        => 15,
                'revenue'           => 1500,
                'transaction_count' => 3,
            ]);
        }

        app(AnomalyDetectionService::class)->runForTenant($this->tenant->id);

        $this->assertNotNull(
            Anomaly::where('tenant_id', $this->tenant->id)->where('rule_type', 'stockout_risk')->where('sku', 'SK-OUT')->first(),
            'a selling SKU at zero stock should be flagged as a stockout, with no reorder_point needed'
        );
        $this->assertNull(
            Anomaly::where('tenant_id', $this->tenant->id)->where('rule_type', 'stockout_risk')->where('sku', 'SK-DEAD')->first(),
            'a zero-stock SKU with no demand is dead stock, not a stockout'
        );
    }

    public function test_negative_inventory_is_flagged_unconditionally(): void
    {
        $store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Store One', 'code' => 'ST01']);
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'NEG-1', 'name' => 'Neg', 'unit_cost' => 5]);

        // Two snapshots: an older positive one, a newer negative one. Latest wins.
        InventoryLevel::create([
            'tenant_id' => $this->tenant->id, 'store_id' => $store->id, 'sku' => 'NEG-1',
            'on_hand_qty' => 10, 'as_of_date' => now()->subDays(5)->format('Y-m-d'),
        ]);
        InventoryLevel::create([
            'tenant_id' => $this->tenant->id, 'store_id' => $store->id, 'sku' => 'NEG-1',
            'on_hand_qty' => -8, 'as_of_date' => now()->subDay()->format('Y-m-d'),
        ]);

        app(AnomalyDetectionService::class)->runForTenant($this->tenant->id);

        $this->assertNotNull(
            Anomaly::where('tenant_id', $this->tenant->id)->where('rule_type', 'negative_inventory')->where('sku', 'NEG-1')->first(),
            'a negative latest on-hand balance must always be flagged'
        );
    }

    public function test_overstock_flags_slow_pile_but_not_no_demand_or_healthy_cover(): void
    {
        $store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Store One', 'code' => 'ST01']);
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'OVR-PILE', 'name' => 'Pile',    'unit_cost' => 50]);
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'OVR-DEAD', 'name' => 'DeadPile', 'unit_cost' => 50]);
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'OVR-OK',   'name' => 'Healthy',  'unit_cost' => 50]);

        foreach (['OVR-PILE' => 1000, 'OVR-DEAD' => 1000, 'OVR-OK' => 60] as $sku => $qty) {
            InventoryLevel::create([
                'tenant_id' => $this->tenant->id, 'store_id' => $store->id, 'sku' => $sku,
                'on_hand_qty' => $qty, 'as_of_date' => now()->subDay()->format('Y-m-d'),
            ]);
        }

        // OVR-PILE and OVR-OK both sell ~2 units/day; OVR-DEAD has no demand.
        for ($i = 1; $i <= 30; $i++) {
            foreach (['OVR-PILE', 'OVR-OK'] as $sku) {
                SalesDaily::create([
                    'tenant_id' => $this->tenant->id, 'store_id' => $store->id, 'sku' => $sku,
                    'date' => now()->subDays($i)->format('Y-m-d'),
                    'units_sold' => 2, 'revenue' => 200, 'transaction_count' => 1,
                ]);
            }
        }

        app(AnomalyDetectionService::class)->runForTenant($this->tenant->id);

        // 1000 units ÷ 2/day = 500 days cover > 120 → overstock, $50k tied up.
        $this->assertNotNull(
            Anomaly::where('tenant_id', $this->tenant->id)->where('rule_type', 'overstock')->where('sku', 'OVR-PILE')->first(),
            'a slow-moving pile far beyond its days-of-cover threshold should be flagged'
        );
        // No demand → dead stock/phantom territory, not overstock.
        $this->assertNull(
            Anomaly::where('tenant_id', $this->tenant->id)->where('rule_type', 'overstock')->where('sku', 'OVR-DEAD')->first(),
            'a SKU with no demand is not overstock'
        );
        // 60 units ÷ 2/day = 30 days cover → healthy, not flagged.
        $this->assertNull(
            Anomaly::where('tenant_id', $this->tenant->id)->where('rule_type', 'overstock')->where('sku', 'OVR-OK')->first(),
            'stock within a normal days-of-cover band should not be flagged'
        );
    }

    public function test_po_late_receipt_flags_late_arrival_but_not_on_time(): void
    {
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'LATE-1', 'name' => 'Late', 'unit_cost' => 10]);
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'ONTIME', 'name' => 'OnTime', 'unit_cost' => 10]);

        // Arrived 18 days after the expected date → well past the 7-day floor.
        PurchaseOrder::create([
            'tenant_id' => $this->tenant->id, 'po_number' => 'PL1', 'supplier' => 'Acme', 'sku' => 'LATE-1',
            'qty_ordered' => 100, 'qty_received' => 100,
            'order_date' => now()->subDays(40)->format('Y-m-d'),
            'expected_date' => now()->subDays(20)->format('Y-m-d'),
            'received_date' => now()->subDays(2)->format('Y-m-d'),
        ]);

        // Arrived on its expected date → not late.
        PurchaseOrder::create([
            'tenant_id' => $this->tenant->id, 'po_number' => 'PL2', 'supplier' => 'Acme', 'sku' => 'ONTIME',
            'qty_ordered' => 100, 'qty_received' => 100,
            'order_date' => now()->subDays(40)->format('Y-m-d'),
            'expected_date' => now()->subDays(5)->format('Y-m-d'),
            'received_date' => now()->subDays(5)->format('Y-m-d'),
        ]);

        app(AnomalyDetectionService::class)->runForTenant($this->tenant->id);

        $this->assertNotNull(
            Anomaly::where('tenant_id', $this->tenant->id)->where('rule_type', 'po_late_receipt')->where('sku', 'LATE-1')->first(),
            'a PO received materially past its expected date should be flagged even though it arrived'
        );
        $this->assertNull(
            Anomaly::where('tenant_id', $this->tenant->id)->where('rule_type', 'po_late_receipt')->where('sku', 'ONTIME')->first(),
            'a PO received on its expected date is not late'
        );
    }

    public function test_receiving_discrepancy_gates_on_shortfall_value(): void
    {
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'PO-BIG',   'name' => 'Big',   'unit_cost' => 100]);
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'PO-CHEAP', 'name' => 'Cheap', 'unit_cost' => 1]);

        // Both are 90 units short on a 100-unit order (well past the 20% threshold).
        foreach (['PO-BIG' => 'P1', 'PO-CHEAP' => 'P2'] as $sku => $po) {
            PurchaseOrder::create([
                'tenant_id'     => $this->tenant->id,
                'po_number'     => $po,
                'supplier'      => 'Acme',
                'sku'           => $sku,
                'qty_ordered'   => 100,
                'qty_received'  => 10,
                'order_date'    => now()->subDays(20)->format('Y-m-d'),
                'received_date' => now()->subDays(2)->format('Y-m-d'),
            ]);
        }

        app(AnomalyDetectionService::class)->runForTenant($this->tenant->id);

        // 90 × $100 = $9,000 → flagged; 90 × $1 = $90 → below the floor, suppressed.
        $this->assertNotNull(
            Anomaly::where('tenant_id', $this->tenant->id)->where('rule_type', 'receiving_discrepancy')->where('sku', 'PO-BIG')->first()
        );
        $this->assertNull(
            Anomaly::where('tenant_id', $this->tenant->id)->where('rule_type', 'receiving_discrepancy')->where('sku', 'PO-CHEAP')->first()
        );
    }

    public function test_rule_gating_skips_demand_rule_for_intermittent_sku(): void
    {
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'GATED',   'name' => 'Intermittent', 'selling_price' => 100]);
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'UNGATED', 'name' => 'Smooth',       'selling_price' => 100]);

        // Chain-level profiles: GATED is intermittent (sales_drop does NOT apply),
        // UNGATED is smooth (sales_drop applies). store_id = 0 = chain-level.
        \App\Models\SkuProfile::create(['tenant_id' => $this->tenant->id, 'sku' => 'GATED',   'store_id' => 0, 'segment' => \App\Models\SkuProfile::SEG_INTERMITTENT]);
        \App\Models\SkuProfile::create(['tenant_id' => $this->tenant->id, 'sku' => 'UNGATED', 'store_id' => 0, 'segment' => \App\Models\SkuProfile::SEG_SMOOTH]);

        // Identical steep drop for both: 100 units historically, 0 recently.
        foreach (['GATED', 'UNGATED'] as $sku) {
            $this->sale($sku, now()->subDays(10)->format('Y-m-d'), 100);
        }

        app(AnomalyDetectionService::class)->runForTenant($this->tenant->id);

        $this->assertNotNull(
            Anomaly::where('tenant_id', $this->tenant->id)->where('rule_type', 'sales_drop')->where('sku', 'UNGATED')->first(),
            'sales_drop applies to a smooth SKU and should fire'
        );
        $this->assertNull(
            Anomaly::where('tenant_id', $this->tenant->id)->where('rule_type', 'sales_drop')->where('sku', 'GATED')->first(),
            'sales_drop should be gated out for an intermittent SKU'
        );
    }

    public function test_demand_erosion_flags_sustained_slide_not_flat_demand(): void
    {
        $store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Store One', 'code' => 'ST01']);
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'ERODE', 'name' => 'Eroding', 'selling_price' => 100]);
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'FLAT',  'name' => 'Flat',    'selling_price' => 100]);

        // 12 points across ~90 days. ERODE slides 24 → 2 (clean linear decline);
        // FLAT holds ~12 every point. Neither is a sharp recent break.
        for ($i = 0; $i < 12; $i++) {
            $date = now()->subDays(88 - $i * 8)->format('Y-m-d');
            SalesDaily::create([
                'tenant_id' => $this->tenant->id, 'store_id' => $store->id, 'sku' => 'ERODE',
                'date' => $date, 'units_sold' => 24 - $i * 2, 'revenue' => (24 - $i * 2) * 100, 'transaction_count' => 1,
            ]);
            SalesDaily::create([
                'tenant_id' => $this->tenant->id, 'store_id' => $store->id, 'sku' => 'FLAT',
                'date' => $date, 'units_sold' => 12, 'revenue' => 1200, 'transaction_count' => 1,
            ]);
        }

        app(AnomalyDetectionService::class)->runForTenant($this->tenant->id);

        $this->assertNotNull(
            Anomaly::where('tenant_id', $this->tenant->id)->where('rule_type', 'demand_erosion')->where('sku', 'ERODE')->first(),
            'a consistent multi-month decline should be flagged even without a sharp recent break'
        );
        $this->assertNull(
            Anomaly::where('tenant_id', $this->tenant->id)->where('rule_type', 'demand_erosion')->where('sku', 'FLAT')->first(),
            'flat demand is not erosion'
        );
    }

    public function test_cumulative_shrink_flags_sawtooth_hidden_from_latest_two(): void
    {
        $store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Store One', 'code' => 'ST01']);
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'SAW', 'name' => 'Sawtooth', 'unit_cost' => 50]);

        // Sawtooth at one location: THREE 100→55 drops (45 lost, ~5 sold →
        // ~40 unexplained each) with restocks back to 100 between them. The LATEST
        // interval is a restock (55→100), so latest-two shrinkage sees no drop,
        // but three separate leaks accumulate (min_intervals = 3).
        $series = [
            ['d' => 40, 'qty' => 100], ['d' => 35, 'qty' => 55],  // decline 1
            ['d' => 28, 'qty' => 100], ['d' => 23, 'qty' => 55],  // decline 2
            ['d' => 16, 'qty' => 100], ['d' => 11, 'qty' => 55],  // decline 3
            ['d' => 1,  'qty' => 100],                             // restock (latest)
        ];
        foreach ($series as $s) {
            InventoryLevel::create([
                'tenant_id' => $this->tenant->id, 'store_id' => $store->id, 'sku' => 'SAW',
                'location' => 'ST01', 'on_hand_qty' => $s['qty'],
                'as_of_date' => now()->subDays($s['d'])->format('Y-m-d'),
            ]);
        }
        // One ~5-unit sale inside each decline window — nowhere near enough to explain the 45-unit drops.
        foreach ([37, 25, 13] as $d) {
            SalesDaily::create([
                'tenant_id' => $this->tenant->id, 'store_id' => $store->id, 'sku' => 'SAW',
                'date' => now()->subDays($d)->format('Y-m-d'), 'units_sold' => 5, 'revenue' => 500, 'transaction_count' => 1,
            ]);
        }

        app(AnomalyDetectionService::class)->runForTenant($this->tenant->id);

        $this->assertNotNull(
            Anomaly::where('tenant_id', $this->tenant->id)->where('rule_type', 'cumulative_shrink')->where('sku', 'SAW')->first(),
            'repeated unexplained losses across the series should be flagged'
        );
        $this->assertNull(
            Anomaly::where('tenant_id', $this->tenant->id)->where('rule_type', 'inventory_shrinkage')->where('sku', 'SAW')->first(),
            'the latest two snapshots are a restock, so point-in-time shrinkage should NOT fire'
        );
    }

    public function test_seasonality_breach_fallback_flags_departure_from_calendar_expectation(): void
    {
        $store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Store One', 'code' => 'ST01']);
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'SEAS-HI', 'name' => 'Spiker', 'selling_price' => 100]);
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'SEAS-OK', 'name' => 'Steady', 'selling_price' => 100]);

        // Baseline window (8–35 days ago): both steady at 10/day.
        for ($i = 8; $i <= 35; $i++) {
            foreach (['SEAS-HI', 'SEAS-OK'] as $sku) {
                SalesDaily::create([
                    'tenant_id' => $this->tenant->id, 'store_id' => $store->id, 'sku' => $sku,
                    'date' => now()->subDays($i)->format('Y-m-d'),
                    'units_sold' => 10, 'revenue' => 1000, 'transaction_count' => 1,
                ]);
            }
        }
        // Recent 7 days: SEAS-HI spikes to 60/day (far above its calendar expectation);
        // SEAS-OK stays at 10/day (right at expectation).
        for ($i = 1; $i <= 7; $i++) {
            SalesDaily::create([
                'tenant_id' => $this->tenant->id, 'store_id' => $store->id, 'sku' => 'SEAS-HI',
                'date' => now()->subDays($i)->format('Y-m-d'), 'units_sold' => 60, 'revenue' => 6000, 'transaction_count' => 1,
            ]);
            SalesDaily::create([
                'tenant_id' => $this->tenant->id, 'store_id' => $store->id, 'sku' => 'SEAS-OK',
                'date' => now()->subDays($i)->format('Y-m-d'), 'units_sold' => 10, 'revenue' => 1000, 'transaction_count' => 1,
            ]);
        }

        app(AnomalyDetectionService::class)->runForTenant($this->tenant->id);

        $this->assertNotNull(
            Anomaly::where('tenant_id', $this->tenant->id)->where('rule_type', 'demand_seasonality_breach')->where('sku', 'SEAS-HI')->first(),
            'a SKU far above its calendar-adjusted expectation should be flagged (seasonal fallback, no year of history needed)'
        );
        $this->assertNull(
            Anomaly::where('tenant_id', $this->tenant->id)->where('rule_type', 'demand_seasonality_breach')->where('sku', 'SEAS-OK')->first(),
            'a SKU right at its expectation is not a seasonality breach'
        );
    }

    public function test_supplier_fill_rate_flags_chronic_underfill_below_per_po_floor(): void
    {
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'CHRON', 'name' => 'Chronic', 'unit_cost' => 10]);

        // 4 POs from one supplier, each 70% filled (30 units short × $10 = $300 —
        // below the $500 receiving floor, so receiving_discrepancy stays silent),
        // but the repeated pattern is a real service failure.
        foreach (['C1', 'C2', 'C3', 'C4'] as $po) {
            PurchaseOrder::create([
                'tenant_id' => $this->tenant->id, 'po_number' => $po, 'supplier' => 'ShakySupplier', 'sku' => 'CHRON',
                'qty_ordered' => 100, 'qty_received' => 70,
                'order_date' => now()->subDays(30)->format('Y-m-d'),
                'received_date' => now()->subDays(20)->format('Y-m-d'),
            ]);
        }

        app(AnomalyDetectionService::class)->runForTenant($this->tenant->id);

        $this->assertNotNull(
            Anomaly::where('tenant_id', $this->tenant->id)->where('rule_type', 'supplier_fill_rate')->where('sku', 'CHRON')->first(),
            'a supplier chronically short across several POs should be flagged in aggregate'
        );
        $this->assertNull(
            Anomaly::where('tenant_id', $this->tenant->id)->where('rule_type', 'receiving_discrepancy')->where('sku', 'CHRON')->first(),
            'no single PO crosses the per-PO dollar floor'
        );
    }
}
