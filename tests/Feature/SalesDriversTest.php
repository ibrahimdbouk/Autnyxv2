<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\Tenant;
use App\Services\Drivers\DriverAnalysis;
use App\Services\Drivers\DriverReports;
use App\Services\Sales\SalesDailyAggregator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Root Cause drivers — the revenue change split into what moved (traffic,
 * basket, mix, price) and why (stock, promotions, events, range), each
 * adding up exactly to the change.
 */
class SalesDriversTest extends TestCase
{
    private Tenant $tenant;
    private Store $a;
    private Store $b;
    private int $receipt = 0;

    /** @var array<string,array<int,array>> rows waiting to be inserted */
    private array $buffer = [];

    /** Comparison: 4 weeks 2026-08-03..08-30; period: week 2026-08-31..09-06. */
    private const C_FROM = '2026-08-03';
    private const C_TO = '2026-08-30';
    private const P_FROM = '2026-08-31';
    private const P_TO = '2026-09-06';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-08 06:00'));
        $this->tenant = $this->createTenant(['currency' => 'AED']);
        $this->a = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Marina', 'code' => 'MAR']);
        $this->b = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Mall', 'code' => 'MAL']);
        foreach ([['MILK', 'Dairy', 'Milk', 5], ['CHEESE', 'Dairy', 'Cheese', 20], ['RICE', 'Grocery', 'Rice', 10], ['OIL', 'Grocery', 'Oil', 15], ['NEW', 'Grocery', 'Rice', 8]] as [$sku, $dept, $cat, $price]) {
            DB::table('products')->insert(['tenant_id' => $this->tenant->id, 'sku' => $sku, 'name' => ucfirst(strtolower($sku)), 'department' => $dept,
                'category' => $cat, 'selling_price' => $price, 'unit_cost' => $price / 2, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** One receipt with these lines. */
    private function receipt(Store $s, string $date, array $lines): void
    {
        $id = 'R' . (++$this->receipt);
        $n = 0;
        foreach ($lines as [$sku, $qty, $price]) {
            $this->buffer['sales_transactions'][] = ['tenant_id' => $this->tenant->id, 'store_id' => $s->id, 'transaction_id' => $id, 'line_no' => ++$n,
                'date' => $date, 'sku' => $sku, 'quantity' => $qty, 'unit_price' => $price, 'total_amount' => $qty * $price,
                'created_at' => now(), 'updated_at' => now()];
        }
    }

    private function stock(Store $s, string $sku, string $date, float $qty): void
    {
        $this->buffer['inventory_levels'][] = ['tenant_id' => $this->tenant->id, 'store_id' => $s->id, 'sku' => $sku, 'as_of_date' => $date,
            'on_hand_qty' => $qty, 'created_at' => now(), 'updated_at' => now()];
    }

    /**
     * Normal day per store: 10 receipts of MILK×2 @5 + RICE×1 @10, 4 receipts of CHEESE @20 + OIL @15.
     * Period: store A has 8 receipts of the first kind (traffic down), cheese price up to 22,
     * RICE out of stock at A for 3 days, OIL on promotion at B (double), NEW launched.
     */
    private function plant(): void
    {
        for ($d = Carbon::parse(self::C_FROM); $d->lte(Carbon::parse(self::P_TO)); $d->addDay()) {
            $date = $d->toDateString();
            $inP = $date >= self::P_FROM;
            foreach ([$this->a, $this->b] as $s) {
                $isA = $s->is($this->a);
                $riceOut = $inP && $isA && $date >= '2026-09-02' && $date <= '2026-09-04';
                $basic = $inP && $isA ? 8 : 10;
                for ($i = 0; $i < $basic; $i++) {
                    $this->receipt($s, $date, array_values(array_filter([['MILK', 2, 5], $riceOut ? null : ['RICE', 1, 10]])));
                }
                $oilQty = $inP && ! $isA ? 2 : 1;
                for ($i = 0; $i < 4; $i++) {
                    $this->receipt($s, $date, [['CHEESE', 1, $inP ? 22 : 20], ['OIL', $oilQty, 15]]);
                }
                if ($inP && ! $isA) {
                    $this->receipt($s, $date, [['NEW', 3, 8]]);
                }
                foreach (['MILK', 'CHEESE', 'RICE', 'OIL'] as $sku) {
                    $this->stock($s, $sku, $date, $sku === 'RICE' && $riceOut ? 0 : 50);
                }
            }
        }
        foreach ($this->buffer as $table => $rows) {
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }
        $this->buffer = [];
        DB::table('promotions')->insert(['tenant_id' => $this->tenant->id, 'promotion_ref' => 'OIL-2X', 'sku' => 'OIL', 'store_id' => $this->b->id,
            'starts_on' => self::P_FROM, 'ends_on' => self::P_TO, 'created_at' => now(), 'updated_at' => now()]);
        app(SalesDailyAggregator::class)->aggregateRange($this->tenant->id, self::C_FROM, self::P_TO);
    }

    public function test_the_parts_add_up_exactly_and_the_planted_drivers_are_found(): void
    {
        $this->plant();
        $r = app(DriverAnalysis::class)->analyze($this->tenant->id, self::P_FROM, self::P_TO, self::C_FROM, self::C_TO);
        $what = DriverAnalysis::map($r['what']);
        $why = DriverAnalysis::map($r['why']);
        $change = $r['totals']['change'];

        $this->assertEqualsWithDelta($change, array_sum($what), 0.001, 'what adds up to the change');
        $this->assertEqualsWithDelta($change - $what['price'], array_sum($why), 0.001, 'why adds up to the non-price change');
        $this->assertSame('receipts', $r['totals']['traffic_basis']);

        // Price: cheese +2 on 4 a day × 2 stores × 7 days = +112.
        $this->assertEqualsWithDelta(112.0, $what['price'], 0.01);
        // Traffic: A lost 2 basic receipts a day.
        $this->assertLessThan(0, $what['traffic']);
        // Out of stock: rice at A, 3 days × ~10 units × 10 ≈ -300 (rate measured on in-stock days).
        $this->assertEqualsWithDelta(-300.0, $why['availability'], 45);
        // Promotion: OIL at B sold +4 a day above normal × 7 × 15 = +420.
        $this->assertEqualsWithDelta(420.0, $why['promotions'], 0.5);
        // Range: NEW launched at B, 3 × 8 × 7 = +168.
        $this->assertEqualsWithDelta(168.0, $why['range'], 0.01);
        // Fewer shoppers at Marina is a cause, not "unexplained".
        $this->assertLessThan(0, $why['store_traffic']);
        $this->assertLessThan(0.15 * abs($change) + 60, abs($why['unexplained']), 'little is left unexplained');

        $stores = collect($r['contributors']['stores'])->keyBy('id');
        $this->assertGreaterThan(0, $stores[$this->b->id]['change']);
        $this->assertLessThan(0, $stores[$this->a->id]['change']);
        $this->assertNotSame('', $r['headline']);
    }

    public function test_without_receipt_numbers_traffic_and_basket_are_volume(): void
    {
        $this->plant();
        DB::table('sales_transactions')->where('tenant_id', $this->tenant->id)->update(['transaction_id' => null]);
        app(SalesDailyAggregator::class)->aggregateRange($this->tenant->id, self::C_FROM, self::P_TO);

        $r = app(DriverAnalysis::class)->analyze($this->tenant->id, self::P_FROM, self::P_TO, self::C_FROM, self::C_TO);
        $what = DriverAnalysis::map($r['what']);
        $this->assertArrayHasKey('volume', $what);
        $this->assertArrayNotHasKey('traffic', $what);
        $this->assertEqualsWithDelta($r['totals']['change'], array_sum($what), 0.001);
        $this->assertNotEmpty($r['notes']);
    }

    public function test_door_counts_split_traffic_into_visitors_and_conversion(): void
    {
        $this->plant();
        for ($d = Carbon::parse(self::C_FROM); $d->lte(Carbon::parse(self::P_TO)); $d->addDay()) {
            foreach ([$this->a, $this->b] as $s) {
                DB::table('store_traffic')->insert(['tenant_id' => $this->tenant->id, 'store_id' => $s->id, 'date' => $d->toDateString(),
                    'visitors' => 40, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $r = app(DriverAnalysis::class)->analyze($this->tenant->id, self::P_FROM, self::P_TO, self::C_FROM, self::C_TO);
        $what = DriverAnalysis::map($r['what']);
        $this->assertSame('visitors', $r['totals']['traffic_basis']);
        $this->assertEqualsWithDelta(0.0, $what['visitors'], 0.01, 'the same visitors came');
        $this->assertLessThan(0, $what['conversion'], 'fewer of them bought at Marina');
        $this->assertEqualsWithDelta($r['totals']['change'], array_sum($what), 0.001);
    }

    public function test_a_category_scope_uses_penetration_and_a_store_scope_stays_in_its_store(): void
    {
        $this->plant();
        $r = app(DriverAnalysis::class)->analyze($this->tenant->id, self::P_FROM, self::P_TO, self::C_FROM, self::C_TO,
            ['department' => 'Grocery', 'category' => 'Rice']);
        $what = DriverAnalysis::map($r['what']);
        $this->assertArrayHasKey('penetration', $what);
        $this->assertEqualsWithDelta($r['totals']['change'], array_sum($what), 0.001);

        $s = app(DriverAnalysis::class)->analyze($this->tenant->id, self::P_FROM, self::P_TO, self::C_FROM, self::C_TO, ['store_id' => $this->a->id]);
        $why = DriverAnalysis::map($s['why']);
        $this->assertEqualsWithDelta(0.0, $why['promotions'], 0.01, 'the promotion was at the other store');
        $this->assertLessThan(0, $why['availability']);
        $this->assertSame([], $s['contributors']['stores']);
    }

    public function test_a_finding_gets_its_own_breakdown_and_is_explained_by_stock(): void
    {
        $this->plant();
        $a = \App\Models\Anomaly::create(['tenant_id' => $this->tenant->id, 'rule_type' => 'sales_drop', 'severity' => 'high', 'sku' => 'RICE',
            'store_id' => $this->a->id, 'description' => 'Rice down', 'detected_at' => now(),
            'context' => ['window' => [self::P_FROM, self::P_TO], 'days' => 7, 'revenue_impact' => 300]]);
        $r = app(DriverReports::class)->forFinding($a);

        $this->assertEqualsCanonicalizing(['sku' => 'RICE', 'store_id' => $this->a->id], $r['scope']);
        $why = DriverAnalysis::map($r['why']);
        $this->assertArrayHasKey('store_traffic', $why);
        $this->assertArrayHasKey('department', $why);
        $this->assertLessThan(0, $why['availability']);
        $this->assertSame('availability', $r['explained']['by']);
        $this->assertEqualsWithDelta($r['totals']['change'] - DriverAnalysis::map($r['what'])['price'], array_sum($why), 0.001);
    }

    public function test_a_calendar_event_is_valued_with_the_lift_it_had_last_time(): void
    {
        app(\App\Services\Calendar\RetailCalendarDefaults::class)->ensure($this->tenant->id, 2025, 2026);
        $prior = \App\Models\RetailEvent::where('tenant_id', $this->tenant->id)->where('key', 'ramadan')->where('year', 2025)->sole();
        $from = $prior->starts_on->copy()->subDays($prior->lead_days);
        $to = $prior->ends_on->copy()->addDays($prior->tail_days);
        $rows = [];
        // Last year: Grocery made 100 a day, 150 a day through Ramadan (+50%).
        for ($d = $from->copy()->subDays(28); $d->lte($to); $d->addDay()) {
            $rows[] = ['tenant_id' => $this->tenant->id, 'store_id' => $this->a->id, 'department' => 'Grocery', 'category' => 'Rice', 'date' => $d->toDateString(),
                'units' => 10, 'revenue' => $d->lt($from) ? 100 : 150, 'skus_sold' => 1, 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('category_daily')->insert($rows);
        // This year: a week inside Ramadan against the four weeks before.
        $now = \App\Models\RetailEvent::where('tenant_id', $this->tenant->id)->where('key', 'ramadan')->where('year', 2026)->sole();
        $pFrom = $now->starts_on->copy()->addDays(7);
        $pTo = $pFrom->copy()->addDays(6);
        $cFrom = $pFrom->copy()->subDays(28);
        $sd = [];
        for ($d = $cFrom->copy(); $d->lte($pTo); $d->addDay()) {
            $sd[] = ['tenant_id' => $this->tenant->id, 'store_id' => $this->a->id, 'sku' => 'RICE', 'date' => $d->toDateString(),
                'units_sold' => 10, 'revenue' => $d->gte($pFrom) ? 150 : 100, 'transaction_count' => 10, 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('sales_daily')->insert($sd);

        $r = app(DriverAnalysis::class)->analyze($this->tenant->id, $pFrom->toDateString(), $pTo->toDateString(), $cFrom->toDateString(), $pFrom->copy()->subDay()->toDateString());
        $why = DriverAnalysis::map($r['why']);
        $ev = collect($r['detail']['events'])->keyBy('key');

        $this->assertTrue($ev['ramadan']['measured']);
        $this->assertEqualsWithDelta(0.5, $ev['ramadan']['lift'], 0.001);
        $this->assertGreaterThan(0, $why['events']);
        $this->assertLessThan(abs($r['totals']['change']), $why['events'] + 0.01);
        $this->assertEqualsWithDelta($r['totals']['change'] - DriverAnalysis::map($r['what'])['price'], array_sum($why), 0.001);
    }

    public function test_weekly_breakdowns_are_stored_and_reused_until_the_data_changes(): void
    {
        $this->plant();
        $reports = app(DriverReports::class);
        $p = $reports->weeklyPeriods($this->tenant->id);
        $this->assertSame(['from' => self::P_FROM, 'to' => self::P_TO, 'cfrom' => self::C_FROM, 'cto' => self::C_TO, 'kind' => 'prior4w'], $p);

        $r = $reports->computeWeekly($this->tenant->id);
        $this->assertSame(1 + 2 + 2, $r['analyses'], 'business + 2 stores + 2 departments');
        $first = $reports->latestWeekly($this->tenant->id);
        $this->travel(1)->hours();
        $reports->computeWeekly($this->tenant->id);
        $this->assertTrue($first->computed_at->eq($reports->latestWeekly($this->tenant->id)->computed_at), 'unchanged data is not recomputed');

        $this->artisan('drivers:compute', ['--tenant' => $this->tenant->id, '--force' => true])->assertSuccessful();
        $this->assertTrue($reports->latestWeekly($this->tenant->id)->computed_at->gt($first->computed_at));
    }

    public function test_the_benchmark_puts_every_planted_change_on_its_driver(): void
    {
        $r = app(\App\Support\Testing\DriverBenchmark::class)->run($this->createTenant()->id);
        foreach ($r as $name => $x) {
            $this->assertTrue($x['pass'], "{$name}: {$x['attributed']} of {$x['change']} put on {$x['driver']}");
        }
        $this->assertCount(6, $r);
    }
}
