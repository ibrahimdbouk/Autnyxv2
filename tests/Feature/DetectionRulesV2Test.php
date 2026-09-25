<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\InventoryLevel;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesReturn;
use App\Models\SalesTransaction;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Anomaly\AnomalyDetectionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WP4.1 (audit C8, C9, C10, H16, H17, H18, H20, M19, M20) — the corrected rule
 * set, on for this tenant through its own detection_rules_v2 setting.
 */
class DetectionRulesV2Test extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant(['currency' => 'AED', 'settings' => ['detection_rules_v2' => true]]);
    }

    private function day(int $ago): string
    {
        return Carbon::today()->subDays($ago)->format('Y-m-d');
    }

    private function store(string $name): Store
    {
        return Store::create(['tenant_id' => $this->tenant->id, 'name' => $name, 'code' => strtoupper(substr(md5($name), 0, 6))]);
    }

    /** One sales_daily row per day, $fromAgo … $toAgo days ago inclusive. */
    private function daily(?int $storeId, string $sku, int $fromAgo, int $toAgo, float $units): void
    {
        $rows = [];
        for ($d = $fromAgo; $d >= $toAgo; $d--) {
            $rows[] = ['tenant_id' => $this->tenant->id, 'store_id' => $storeId, 'sku' => $sku, 'date' => $this->day($d),
                'units_sold' => $units, 'revenue' => $units * 10, 'transaction_count' => 1, 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('sales_daily')->insert($rows);
    }

    private function snapshot(Store $store, string $sku, int $ago, float $qty, ?float $reorder = null): void
    {
        InventoryLevel::create(['tenant_id' => $this->tenant->id, 'store_id' => $store->id, 'sku' => $sku, 'location' => $store->name,
            'on_hand_qty' => $qty, 'reorder_point' => $reorder, 'as_of_date' => $this->day($ago)]);
    }

    private function detect(): void
    {
        app(AnomalyDetectionService::class)->runForTenant($this->tenant->id);
    }

    private function flags(string $rule)
    {
        return Anomaly::where('tenant_id', $this->tenant->id)->where('rule_type', $rule)->get();
    }

    public function test_store_outlier_is_quiet_for_healthy_stores_of_any_size_and_names_the_store_that_collapsed(): void
    {
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'MILK', 'name' => 'Milk', 'selling_price' => 20]);
        $stores = [];
        foreach (range(1, 10) as $i) {
            $stores[$i] = $this->store("S{$i}");
            // Sizes from 5 to 50 units/day; each keeps its own rate (±1 unit of noise).
            $this->daily($stores[$i]->id, 'MILK', 34, 7, 5 * $i);
            $this->daily($stores[$i]->id, 'MILK', 6, 0, 5 * $i + ($i % 2 ? 1 : -1));
        }
        $this->detect();
        $this->assertCount(0, $this->flags('store_outlier'), 'ten healthy stores → no flags');

        $bad = $this->store('S11');
        $this->daily($bad->id, 'MILK', 34, 7, 40);
        $this->daily($bad->id, 'MILK', 6, 0, 4);
        $this->detect();

        $flags = $this->flags('store_outlier');
        $this->assertCount(1, $flags);
        $this->assertSame($bad->id, (int) $flags->first()->store_id, 'flagged on the store, not chain-wide');
    }

    public function test_shrink_is_explained_only_by_the_same_stores_sales(): void
    {
        $a = $this->store('A');
        $b = $this->store('B');
        foreach ([$a, $b] as $s) {
            $this->snapshot($s, 'TV', 5, 100);
            $this->snapshot($s, 'TV', 1, 60);
        }
        $this->daily($a->id, 'TV', 4, 1, 10);   // A sold 40 → its drop is explained
        $this->daily($b->id, 'SOAP', 4, 1, 3);  // B is in the sales feed but sold no TVs

        $this->detect();

        $flags = $this->flags('inventory_shrinkage');
        $this->assertCount(1, $flags, "A's sales must not explain B's loss");
        $this->assertSame($b->id, (int) $flags->first()->store_id);
        $this->assertEqualsWithDelta(40, $flags->first()->context['unexplained'], 0.01);
    }

    public function test_no_shrink_is_read_into_a_period_the_sales_feed_has_not_reached(): void
    {
        $a = $this->store('A');
        $this->daily($a->id, 'TV', 20, 6, 1);   // sales stop 6 days ago
        $this->snapshot($a, 'TV', 8, 100);
        $this->snapshot($a, 'TV', 1, 60);       // snapshot after the sales clock

        $this->detect();

        $this->assertCount(0, $this->flags('inventory_shrinkage'));
    }

    public function test_an_inventory_feed_ahead_of_sales_does_not_empty_the_sales_windows(): void
    {
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'RICE', 'name' => 'Rice', 'selling_price' => 50]);
        $a = $this->store('A');
        foreach (range(50, 10) as $ago) {             // steady sales up to 10 days ago
            SalesTransaction::create(['tenant_id' => $this->tenant->id, 'store_id' => $a->id, 'sku' => 'RICE', 'date' => $this->day($ago), 'quantity' => 10]);
        }
        $this->daily($a->id, 'RICE', 50, 10, 10);
        $this->snapshot($a, 'RICE', 0, 500);          // inventory is current

        $this->detect();

        $this->assertCount(0, $this->flags('sales_drop'), 'the sales clock is the last sales day, not the inventory day');
    }

    public function test_a_seven_day_window_is_seven_days_and_the_history_does_not_overlap_it(): void
    {
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'OIL', 'name' => 'Oil', 'selling_price' => 100]);
        $this->daily(null, 'OIL', 34, 7, 10);
        $this->daily(null, 'OIL', 6, 0, 5);

        $this->detect();

        $flag = $this->flags('sales_drop')->sole();
        $this->assertEqualsWithDelta(5.0, $flag->context['recent_daily'], 0.001);
        $this->assertEqualsWithDelta(10.0, $flag->context['expected_daily'], 0.001);
        $this->assertSame(7, $flag->context['days']);
    }

    public function test_sku_less_and_supplier_rules_update_one_anomaly_across_runs(): void
    {
        foreach (['Acme' => [5, 15], 'Beta' => [4, 12]] as $supplier => [$before, $now]) {
            foreach (range(1, 3) as $i) {
                PurchaseOrder::create(['tenant_id' => $this->tenant->id, 'po_number' => "{$supplier}-old-{$i}", 'supplier' => $supplier, 'sku' => 'X',
                    'qty_ordered' => 10, 'qty_received' => 10, 'order_date' => $this->day(120 + $i), 'received_date' => $this->day(120 + $i - $before)]);
                PurchaseOrder::create(['tenant_id' => $this->tenant->id, 'po_number' => "{$supplier}-new-{$i}", 'supplier' => $supplier, 'sku' => 'X',
                    'qty_ordered' => 10, 'qty_received' => 10, 'order_date' => $this->day(30 + $i), 'received_date' => $this->day(30 + $i - $now)]);
            }
        }
        foreach (['A' => 900, 'B' => 50, 'C' => 30, 'D' => 20] as $sku => $amount) {
            SalesTransaction::create(['tenant_id' => $this->tenant->id, 'sku' => $sku, 'date' => $this->day(3), 'quantity' => 1, 'total_amount' => $amount]);
        }

        $this->detect();
        $this->detect();
        $this->detect();

        $this->assertCount(2, $this->flags('supplier_lead_time_drift'), 'one per supplier, not one per night');
        $this->assertCount(1, $this->flags('revenue_concentration_risk'));
    }

    public function test_each_overdue_po_line_is_its_own_anomaly(): void
    {
        foreach (['P1', 'P2'] as $po) {
            PurchaseOrder::create(['tenant_id' => $this->tenant->id, 'po_number' => $po, 'supplier' => 'Acme', 'sku' => 'X',
                'qty_ordered' => 10, 'order_date' => $this->day(20), 'expected_date' => $this->day(10)]);
        }
        PurchaseOrder::create(['tenant_id' => $this->tenant->id, 'po_number' => 'P3', 'supplier' => 'Acme', 'sku' => 'X', 'status' => 'cancelled',
            'qty_ordered' => 10, 'order_date' => $this->day(20), 'expected_date' => $this->day(10)]);

        $this->detect();

        $this->assertEqualsCanonicalizing(['P1', 'P2'], $this->flags('po_overdue')->pluck('context.po_number')->all());
    }

    public function test_stockout_charges_only_the_demand_stock_on_hand_cannot_cover(): void
    {
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'COVERED', 'name' => 'C', 'selling_price' => 100]);
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'SHORT', 'name' => 'S', 'selling_price' => 100]);
        $a = $this->store('A');
        $this->daily($a->id, 'COVERED', 29, 0, 2);
        $this->daily($a->id, 'SHORT', 29, 0, 2);
        $this->snapshot($a, 'COVERED', 0, 50, 60); // below its reorder point but covers 7 days (14 units)
        $this->snapshot($a, 'SHORT', 0, 5, 60);

        $this->detect();

        $flags = $this->flags('stockout_risk')->keyBy('sku');
        $this->assertFalse($flags->has('COVERED'));
        $this->assertEqualsWithDelta(9, $flags['SHORT']->context['lost_units'], 0.01, '2/day × 7 − 5 on hand');
    }

    public function test_a_position_that_left_the_inventory_feed_is_not_judged_and_lots_are_summed(): void
    {
        $a = $this->store('A');
        $this->snapshot($a, 'GONE', 40, -5);       // last seen 40 days ago
        $this->snapshot($a, 'FRESH', 0, 20);
        InventoryLevel::create(['tenant_id' => $this->tenant->id, 'store_id' => $a->id, 'sku' => 'LOTS', 'location' => 'A',
            'on_hand_qty' => -3, 'batch_ref' => 'L1', 'as_of_date' => $this->day(0)]);
        InventoryLevel::create(['tenant_id' => $this->tenant->id, 'store_id' => $a->id, 'sku' => 'LOTS', 'location' => 'A',
            'on_hand_qty' => 8, 'batch_ref' => 'L2', 'as_of_date' => $this->day(0)]);

        $this->detect();

        $this->assertCount(0, $this->flags('negative_inventory'), 'stale position skipped; lots net to +5');
    }

    public function test_a_return_in_both_the_returns_feed_and_the_sales_lines_counts_once(): void
    {
        SalesTransaction::create(['tenant_id' => $this->tenant->id, 'sku' => 'SHOE', 'date' => $this->day(3), 'quantity' => 100]);
        SalesTransaction::create(['tenant_id' => $this->tenant->id, 'sku' => 'SHOE', 'date' => $this->day(2), 'quantity' => -10]);
        SalesReturn::create(['tenant_id' => $this->tenant->id, 'sku' => 'SHOE', 'date' => $this->day(2), 'quantity' => 10]);
        $this->daily(null, 'SHOE', 3, 2, 45);

        $this->detect();

        $this->assertCount(0, $this->flags('return_rate_spike'), '10 of 100 sold = 10% < 15%');
    }

    public function test_price_anomaly_needs_several_deviating_days_per_store_and_ignores_promotions(): void
    {
        $a = $this->store('A');
        $b = $this->store('B');
        foreach (range(40, 100) as $ago) {
            SalesTransaction::create(['tenant_id' => $this->tenant->id, 'store_id' => $a->id, 'sku' => 'TEA', 'date' => $this->day($ago), 'quantity' => 1, 'unit_price' => 10]);
        }
        // A: one odd till line — not a pricing problem. A promotion week — excluded.
        SalesTransaction::create(['tenant_id' => $this->tenant->id, 'store_id' => $a->id, 'sku' => 'TEA', 'date' => $this->day(5), 'quantity' => 1, 'unit_price' => 4]);
        foreach (range(10, 14) as $ago) {
            SalesTransaction::create(['tenant_id' => $this->tenant->id, 'store_id' => $a->id, 'sku' => 'TEA', 'date' => $this->day($ago), 'quantity' => 1, 'unit_price' => 6, 'promotion_ref' => 'RAMADAN']);
        }
        // B: priced at 6 for four days.
        foreach (range(1, 4) as $ago) {
            SalesTransaction::create(['tenant_id' => $this->tenant->id, 'store_id' => $b->id, 'sku' => 'TEA', 'date' => $this->day($ago), 'quantity' => 2, 'unit_price' => 6]);
        }

        $this->detect();

        $flag = $this->flags('price_anomaly')->sole();
        $this->assertSame($b->id, (int) $flag->store_id);
        $this->assertSame(4, $flag->context['deviating_days']);
        $this->assertEqualsWithDelta(32, $flag->context['revenue_impact'], 0.01, '(10 − 6) × 2 units × 4 days');
        $this->assertStringContainsString('AED', $flag->description);
    }

    public function test_a_dismissed_anomaly_stays_quiet_until_it_materially_worsens(): void
    {
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'LAMP', 'name' => 'Lamp', 'unit_cost' => 50]);
        $a = $this->store('A');
        $this->daily($a->id, 'SOAP', 10, 0, 1);
        $this->snapshot($a, 'LAMP', 9, 100);
        $this->snapshot($a, 'LAMP', 0, 70);   // 30 units × 50 = 1,500 unexplained

        $this->detect();
        $first = $this->flags('inventory_shrinkage')->sole();
        $first->update(['dismissed_at' => now(), 'is_false_positive' => false]);

        $this->detect();
        $this->detect();
        $this->assertCount(1, $this->flags('inventory_shrinkage'), 'no new row while it persists unchanged');

        InventoryLevel::where('sku', 'LAMP')->where('as_of_date', $this->day(0))->update(['on_hand_qty' => 20]); // 80 units → 4,000
        $this->detect();

        $episodes = $this->flags('inventory_shrinkage')->sortBy('episode_seq')->values();
        $this->assertCount(2, $episodes, 'a materially worse loss opens a new episode');
        $this->assertSame($first->id, (int) $episodes[1]->previous_episode_id);
    }

    public function test_a_long_unchanged_reorder_point_is_flagged_whatever_the_items_segment(): void
    {
        $a = $this->store('A');
        foreach ([120, 60, 30, 1] as $ago) {
            $this->snapshot($a, 'OLD', $ago, 40, 25);   // same reorder point for 120 days
            $this->snapshot($a, 'NEW', $ago, 40, $ago > 30 ? 25 : 30); // changed 30 days ago
        }
        $this->daily($a->id, 'OLD', 20, 0, 3);
        $this->daily($a->id, 'NEW', 20, 0, 3);
        foreach (['OLD', 'NEW'] as $sku) {
            DB::table('sku_profiles')->insert(['tenant_id' => $this->tenant->id, 'sku' => $sku, 'store_id' => $a->id, 'segment' => 'smooth',
                'created_at' => now(), 'updated_at' => now()]);
        }

        $this->detect();

        $flag = $this->flags('reorder_point_staleness')->sole();
        $this->assertSame('OLD', $flag->sku);
        $this->assertSame($this->day(120), $flag->context['unchanged_since']);
    }

    public function test_money_in_descriptions_uses_the_tenant_currency(): void
    {
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'CAN', 'name' => 'Can', 'selling_price' => 5, 'unit_cost' => 8]);

        $this->detect();

        $this->assertStringNotContainsString('$', $this->flags('discount_signal')->sole()->description);
        $this->assertStringContainsString('AED', $this->flags('discount_signal')->sole()->description);
    }

    public function test_the_dry_run_writes_nothing_and_reports_both_rule_sets(): void
    {
        $a = $this->store('A');
        $b = $this->store('B');
        foreach ([$a, $b] as $s) {
            $this->snapshot($s, 'TV', 5, 100);
            $this->snapshot($s, 'TV', 1, 60);
        }
        $this->daily($a->id, 'TV', 4, 1, 10);
        $this->daily($b->id, 'SOAP', 4, 1, 3);

        $svc = app(AnomalyDetectionService::class);
        $v1 = collect($svc->dryRun($this->tenant->id, false))->where('rule', 'inventory_shrinkage');
        $v2 = collect($svc->dryRun($this->tenant->id, true))->where('rule', 'inventory_shrinkage');

        $this->assertSame(0, Anomaly::count());
        $this->assertCount(0, $v1, 'v1 lets store A\'s sales explain store B\'s loss');
        $this->assertSame([$b->id], $v2->pluck('store_id')->all());
    }
}
