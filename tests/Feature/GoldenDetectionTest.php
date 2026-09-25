<?php

namespace Tests\Feature;

use App\Models\AnomalySetting;
use App\Models\Anomaly;
use App\Models\InventoryLevel;
use App\Models\Investigation;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Anomaly\AnomalyDetectionService;
use App\Services\Anomaly\InvestigationCorrelationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * WP4.7 — golden datasets with hand-computed answers, run through the full
 * v2 pipeline (detection → correlation → revenue at risk).
 *
 * Also part of the pack, next to their fix:
 *   • multi-store shrink, lagging sales feed, store outlier with 10 healthy
 *     stores = 0 flags, null-SKU rules idempotent across 3 runs, dismissal
 *     stickiness ................................ DetectionRulesV2Test (WP4.1)
 *   • robust baselines .......................... RobustBaselinesTest (WP4.2)
 *   • one loss counted once ..................... ValueModelTest (WP4.4)
 */
class GoldenDetectionTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant(['settings' => ['detection_rules_v2' => true]]);
    }

    private function day(int $ago): string
    {
        return Carbon::today()->subDays($ago)->toDateString();
    }

    private function daily(?int $store, string $sku, int $fromAgo, int $toAgo, float $units): void
    {
        $rows = [];
        for ($d = $fromAgo; $d >= $toAgo; $d--) {
            $rows[] = ['tenant_id' => $this->tenant->id, 'store_id' => $store, 'sku' => $sku, 'date' => $this->day($d),
                'units_sold' => $units, 'revenue' => $units * 10, 'transaction_count' => 1, 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('sales_daily')->insert($rows);
    }

    public static function windows(): array
    {
        return ['7 days' => [7], '30 days' => [30], '60 days' => [60]];
    }

    #[DataProvider('windows')]
    public function test_a_drop_window_of_n_days_is_exactly_n_days_against_the_28_before(int $days): void
    {
        AnomalySetting::seedForTenant($this->tenant->id);
        AnomalySetting::where('tenant_id', $this->tenant->id)->where('rule_type', 'sales_drop')->update(['thresholds' => ['pct' => 30, 'days' => $days, 'min_revenue' => 0]]);
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'OIL', 'name' => 'Oil', 'selling_price' => 10]);
        $this->daily(null, 'OIL', $days + 27, $days, 12);   // exactly the 28 days before the window
        $this->daily(null, 'OIL', $days + 40, $days + 28, 99); // older — outside both windows
        $this->daily(null, 'OIL', $days - 1, 0, 6);          // the window

        app(AnomalyDetectionService::class)->runForTenant($this->tenant->id);

        $c = Anomaly::where('rule_type', 'sales_drop')->sole()->context;
        $this->assertSame($days, $c['days']);
        $this->assertEqualsWithDelta(6.0, $c['recent_daily'], 0.0001);
        $this->assertEqualsWithDelta(12.0, $c['expected_daily'], 0.0001);
        $this->assertEqualsWithDelta(6 * $days * 10, $c['revenue_impact'], 0.01, '6 units/day short × N days × 10');
    }

    public function test_a_po_received_with_no_quantity_recorded_counts_as_nothing_received(): void
    {
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'X', 'name' => 'X', 'unit_cost' => 100]);
        PurchaseOrder::create(['tenant_id' => $this->tenant->id, 'po_number' => 'P9', 'supplier' => 'Acme', 'sku' => 'X', 'qty_ordered' => 20,
            'qty_received' => null, 'order_date' => $this->day(15), 'expected_date' => $this->day(5), 'received_date' => $this->day(3)]);

        app(AnomalyDetectionService::class)->runForTenant($this->tenant->id);

        $a = Anomaly::where('rule_type', 'receiving_discrepancy')->sole();
        $this->assertSame('po:P9', $a->context['subject']);
        $this->assertEqualsWithDelta(2000, $a->context['goods_value'], 0.01, '20 short × 100');
        $this->assertSame('capital_at_cost', $a->value_type);
    }

    public function test_one_lost_sale_seen_by_two_rules_is_one_investigation_and_counted_once(): void
    {
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'MILK', 'name' => 'Milk', 'selling_price' => 10]);
        $s = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'S1']);
        $this->daily($s->id, 'MILK', 34, 7, 100);
        $this->daily($s->id, 'MILK', 6, 0, 20);                              // chain drop: 80/day × 7 × 10 = 5,600
        InventoryLevel::create(['tenant_id' => $this->tenant->id, 'store_id' => $s->id, 'sku' => 'MILK', 'location' => 'S1',
            'on_hand_qty' => 0, 'reorder_point' => 50, 'as_of_date' => $this->day(0)]); // stockout at S1

        app(AnomalyDetectionService::class)->runForTenant($this->tenant->id);
        app(InvestigationCorrelationService::class)->correlateForTenant($this->tenant->id);

        $this->assertTrue(Anomaly::where('rule_type', 'stockout_risk')->exists());
        $this->assertTrue(Anomaly::where('rule_type', 'sales_drop')->exists());
        $inv = Investigation::where('primary_sku', 'MILK')->sole();
        $members = Anomaly::where('investigation_id', $inv->id)->where('value_type', 'lost_revenue')->get();
        $this->assertTrue($members->pluck('rule_type')->contains('stockout_risk'), 'every MILK signal is in the one investigation');
        $v = fn ($a) => (float) ($a->context['revenue_impact'] ?? 0);
        $chain = $members->whereNull('store_id')->map($v)->max() ?? 0;
        $store = $members->where('store_id', $s->id)->map($v)->max() ?? 0;
        $this->assertEqualsWithDelta(max($chain, $store), $inv->revenue_at_risk, 0.01, 'the larger view of the same loss, not the sum');
        $this->assertLessThan($members->map($v)->sum(), $inv->revenue_at_risk);
    }
}
