<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\Import;
use App\Models\Product;
use App\Models\RetailEvent;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Anomaly\AnomalyDetectionService;
use App\Services\Calendar\RetailCalendarDefaults;
use App\Services\DataQuality\DataQualityChecks;
use App\Services\Fx\FxService;
use App\Services\Import\ImportProcessorService;
use App\Services\Sales\SalesDailyAggregator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * W13 — the retail calendar (Ramadan, Eid, back to school, …) explains demand
 * swings only when the item's department moved the same way; weekly and
 * monthly sales; exchange rates; PO lines in selling units.
 */
class RetailCalendarAndUnitsTest extends TestCase
{
    protected function tearDown(): void
    {
        RetailCalendarDefaults::forget();
        parent::tearDown();
    }

    // ── Calendar ────────────────────────────────────────────────────────────

    public function test_the_default_calendar_uses_umm_al_qura_dates_and_is_never_overwritten(): void
    {
        $t = $this->createTenant();
        $n = app(RetailCalendarDefaults::class)->ensure($t->id, 2026, 2026);
        $this->assertGreaterThan(10, $n);
        $e = fn (string $key) => RetailEvent::where('tenant_id', $t->id)->where('key', $key)->where('year', 2026)->sole();

        $this->assertSame(['2026-02-18', '2026-03-19'], [$e('ramadan')->starts_on->toDateString(), $e('ramadan')->ends_on->toDateString()]);
        $this->assertSame('2026-03-20', $e('eid_al_fitr')->starts_on->toDateString());
        $this->assertSame('2026-05-26', $e('eid_al_adha')->starts_on->toDateString(), 'from the day of Arafat');
        $this->assertSame('2026-11-27', $e('white_friday')->starts_on->toDateString(), 'fourth Friday of November');
        $this->assertSame(['AE'], $e('uae_national_day')->countries);
        $this->assertFalse($e('valentines_day')->active, 'gift occasions start switched off');

        $e('ramadan')->update(['starts_on' => '2026-02-19']);   // moon sighting
        RetailCalendarDefaults::forget();
        $this->assertSame(0, app(RetailCalendarDefaults::class)->ensure($t->id, 2026, 2026));
        $this->assertSame('2026-02-19', $e('ramadan')->starts_on->toDateString(), 'a tenant edit stays');
    }

    private function series(int $tenantId, int $storeId, string $sku, Carbon $end, callable $units): void
    {
        $rows = [];
        for ($ago = 34; $ago >= 0; $ago--) {
            $u = $units($ago);
            $rows[] = ['tenant_id' => $tenantId, 'store_id' => $storeId, 'sku' => $sku, 'date' => $end->copy()->subDays($ago)->toDateString(),
                'units_sold' => $u, 'revenue' => $u * 10, 'transaction_count' => 1, 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('sales_daily')->insert($rows);
    }

    public function test_a_ramadan_surge_across_the_department_is_not_flagged_but_a_lone_spike_is(): void
    {
        $t = $this->createTenant(['settings' => ['detection_rules_v2' => true]]);
        $store = Store::create(['tenant_id' => $t->id, 'name' => 'Marina', 'code' => 'MAR'])->id;
        $end = Carbon::parse('2026-03-05');   // inside Ramadan 2026 (18 Feb – 19 Mar)
        for ($i = 1; $i <= 8; $i++) {        // Grocery: every item triples in Ramadan's first weeks
            Product::create(['tenant_id' => $t->id, 'sku' => "G{$i}", 'name' => "G{$i}", 'unit_cost' => 6, 'selling_price' => 10, 'department' => 'Grocery']);
            $this->series($t->id, $store, "G{$i}", $end, fn ($ago) => $ago < 7 ? 150 : 50);
        }
        for ($i = 1; $i <= 6; $i++) {        // Toys: flat — except one item that spikes on its own
            Product::create(['tenant_id' => $t->id, 'sku' => "T{$i}", 'name' => "T{$i}", 'unit_cost' => 6, 'selling_price' => 10, 'department' => 'Toys']);
            $this->series($t->id, $store, "T{$i}", $end, fn ($ago) => $i === 1 && $ago < 7 ? 250 : 50);
        }

        $svc = app(AnomalyDetectionService::class);
        $svc->runForTenant($t->id);

        $spiked = Anomaly::where('tenant_id', $t->id)->where('rule_type', 'sales_spike')->pluck('sku')->unique()->values()->all();
        $this->assertSame(['T1'], $spiked, 'the Ramadan surge across Grocery is the calendar; the lone toy is not');
        $this->assertGreaterThanOrEqual(8, $svc->eventSuppressedByRule()['sales_spike'] ?? 0);

        // Off: the same surge is flagged like any other.
        config(['detection.event_suppression' => false]);
        app(AnomalyDetectionService::class)->runForTenant($t->id);
        $this->assertCount(9, Anomaly::where('tenant_id', $t->id)->where('rule_type', 'sales_spike')->pluck('sku')->unique());
    }

    public function test_a_national_day_only_explains_stores_in_that_country(): void
    {
        $t = $this->createTenant();
        $dubai = Store::create(['tenant_id' => $t->id, 'name' => 'Dubai', 'country' => 'United Arab Emirates'])->id;
        $riyadh = Store::create(['tenant_id' => $t->id, 'name' => 'Riyadh', 'country' => 'KSA'])->id;
        app(RetailCalendarDefaults::class)->ensure($t->id, 2025, 2026);
        $cal = \App\Services\Calendar\RetailCalendar::load($t->id, '2025-11-01', '2025-12-10');

        $this->assertSame('uae_national_day', $cal->explains(null, $dubai, '2025-12-02', '2025-12-04', '2025-11-05', '2025-11-20')['key'] ?? null);
        $this->assertNull($cal->explains(null, $riyadh, '2025-12-02', '2025-12-04', '2025-11-05', '2025-11-20'));
    }

    // ── Weekly / monthly ────────────────────────────────────────────────────

    public function test_weekly_and_monthly_sales_follow_the_daily_rebuild(): void
    {
        $t = $this->createTenant();
        $s = Store::create(['tenant_id' => $t->id, 'name' => 'S'])->id;
        foreach (['2026-08-30', '2026-08-31', '2026-09-01', '2026-09-07'] as $d) {   // Sun, Mon, Tue, next Mon
            DB::table('sales_transactions')->insert(['tenant_id' => $t->id, 'store_id' => $s, 'sku' => 'A', 'date' => $d, 'quantity' => 2, 'total_amount' => 20,
                'created_at' => now(), 'updated_at' => now()]);
        }
        app(SalesDailyAggregator::class)->aggregateRange($t->id, '2026-08-01', '2026-09-30');

        $weeks = DB::table('sales_weekly')->where('tenant_id', $t->id)->orderBy('week_start')->get();
        $this->assertSame(['2026-08-24', '2026-08-31', '2026-09-07'], $weeks->map(fn ($w) => substr((string) $w->week_start, 0, 10))->all(), 'ISO weeks start on Monday');
        $this->assertEquals([2, 4, 2], $weeks->pluck('units_sold')->map(fn ($v) => (float) $v)->all());
        $this->assertEquals([2, 1], [$weeks[1]->days_sold, $weeks[2]->days_sold]);
        $months = DB::table('sales_monthly')->where('tenant_id', $t->id)->orderBy('month_start')->pluck('revenue', 'month_start');
        $this->assertEquals([40, 40], array_values($months->map(fn ($v) => (float) $v)->all()));

        DB::table('sales_transactions')->where('date', '2026-09-07')->delete();   // a rollback
        app(SalesDailyAggregator::class)->aggregateRange($t->id, '2026-09-07', '2026-09-07');
        $this->assertSame(0, DB::table('sales_weekly')->where('tenant_id', $t->id)->where('week_start', '2026-09-07')->count());
        $this->assertEquals(20, (float) DB::table('sales_monthly')->where('tenant_id', $t->id)->where('month_start', '2026-09-01')->value('revenue'));
    }

    // ── Exchange rates ──────────────────────────────────────────────────────

    public function test_sales_in_another_currency_are_converted_and_a_missing_rate_is_reported(): void
    {
        $t = $this->createTenant(['currency' => 'AED']);
        $riyadh = Store::create(['tenant_id' => $t->id, 'name' => 'Riyadh', 'currency' => 'SAR'])->id;
        $kuwait = Store::create(['tenant_id' => $t->id, 'name' => 'Kuwait', 'currency' => 'KWD'])->id;
        $dubai = Store::create(['tenant_id' => $t->id, 'name' => 'Dubai'])->id;
        $this->assertSame(5, app(FxService::class)->seedPegs($t->id));
        foreach ([$riyadh, $kuwait, $dubai] as $s) {
            DB::table('sales_transactions')->insert(['tenant_id' => $t->id, 'store_id' => $s, 'sku' => 'A', 'date' => '2026-09-10', 'quantity' => 1,
                'total_amount' => 100, 'created_at' => now(), 'updated_at' => now()]);
        }
        app(SalesDailyAggregator::class)->aggregateRange($t->id, '2026-09-10', '2026-09-10');
        $rev = DB::table('sales_daily')->where('tenant_id', $t->id)->pluck('revenue', 'store_id')->map(fn ($v) => round((float) $v, 2));
        $this->assertSame([97.93, 100.0, 100.0], [$rev[$riyadh], $rev[$kuwait], $rev[$dubai]], 'SAR at 3.6725/3.75; KWD has no rate yet');

        $this->assertSame(['KWD'], app(FxService::class)->missing($t->id));
        app(DataQualityChecks::class)->run($t->id, alert: false);
        $this->assertTrue(\App\Models\DqFinding::where('tenant_id', $t->id)->where('check', 'fx_rate_missing')->open()->exists());

        DB::table('fx_rates')->insert(['tenant_id' => $t->id, 'currency' => 'KWD', 'valid_from' => '2026-01-01', 'rate' => 11.95, 'created_at' => now(), 'updated_at' => now()]);
        (new \App\Jobs\Fx\ReapplyExchangeRatesJob($t->id))->handle(app(SalesDailyAggregator::class), app(\App\Services\Supply\PurchaseOrderNormalizer::class));
        $this->assertEquals(1195, round((float) DB::table('sales_daily')->where('store_id', $kuwait)->value('revenue'), 2));
    }

    // ── Pack sizes ──────────────────────────────────────────────────────────

    public function test_po_lines_in_cases_are_held_in_units_once_the_pack_size_is_known(): void
    {
        Storage::fake('local');
        Queue::fake();
        $t = $this->createTenant(['currency' => 'AED']);
        Product::create(['tenant_id' => $t->id, 'sku' => 'WATER', 'name' => 'Water 500ml', 'units_per_case' => 24]);
        Product::create(['tenant_id' => $t->id, 'sku' => 'RICE', 'name' => 'Rice 5kg']);
        app(FxService::class)->seedPegs($t->id);

        $map = ['PO' => 'po_number', 'Sup' => 'supplier', 'Item' => 'sku', 'Qty' => 'qty_ordered', 'Rec' => 'qty_received', 'Cost' => 'unit_cost',
            'Date' => 'order_date', 'Unit' => 'uom', 'Cur' => 'currency'];
        $csv = "PO,Sup,Item,Qty,Rec,Cost,Date,Unit,Cur\nP1,Aqua,WATER,10,9,48,2026-09-01,CS,\nP2,Aqua,WATER,5,5,2,2026-09-01,EA,\nP3,Grain,RICE,4,4,100,2026-09-01,carton,USD\n";
        $this->runImport($this->csvImport($t->id, Import::TYPE_PURCHASE_ORDERS, $csv, $map));

        $po = fn (string $n) => DB::table('purchase_orders')->where('tenant_id', $t->id)->where('po_number', $n)->first();
        $this->assertEquals([240, 216, 2, 24], [(float) $po('P1')->qty_ordered, (float) $po('P1')->qty_received, (float) $po('P1')->unit_cost, (float) $po('P1')->pack_factor]);
        $this->assertEquals([5, 1], [(float) $po('P2')->qty_ordered, (float) $po('P2')->pack_factor]);
        $this->assertNull($po('P3')->pack_factor, 'no pack size for rice yet');
        $this->assertEquals([100, 367.25], [(float) $po('P3')->unit_cost_original, round((float) $po('P3')->unit_cost, 2)], 'USD converted at the peg');

        app(DataQualityChecks::class)->run($t->id, alert: false);
        $this->assertTrue(\App\Models\DqFinding::where('tenant_id', $t->id)->where('check', 'po_case_without_pack')->open()->exists());

        // The product file brings the pack size: the waiting line is converted.
        $this->runImport($this->csvImport($t->id, Import::TYPE_PRODUCTS, "SKU,Name,Case\nRICE,Rice 5kg,4\n", ['SKU' => 'sku', 'Name' => 'name', 'Case' => 'units_per_case']));
        $this->assertEquals([16, 4], [(float) $po('P3')->qty_ordered, (float) $po('P3')->pack_factor]);
        $this->assertEqualsWithDelta(91.81, (float) $po('P3')->unit_cost, 0.01, '367.25 per carton of 4');

        // Re-sent: reset to the raw line and converted once — never twice.
        $this->runImport($this->csvImport($t->id, Import::TYPE_PURCHASE_ORDERS, $csv, $map));
        $this->assertEquals([240, 2], [(float) $po('P1')->qty_ordered, (float) $po('P1')->unit_cost]);
    }

    private function runImport(Import $import): Import
    {
        $svc = app(ImportProcessorService::class);
        $svc->startChunkedImport($import);
        $guard = 0;
        do {
            $r = $svc->processChunk($import->fresh(), 100);
        } while (! ($r['done'] ?? false) && ++$guard < 50);

        return $import->fresh();
    }

    private function csvImport(int $tenantId, string $type, string $csv, array $map): Import
    {
        $path = 'imports/pending/c-' . uniqid() . '.csv';
        Storage::disk('local')->put($path, $csv);
        $import = Import::create(['tenant_id' => $tenantId, 'original_filename' => $type . '-' . uniqid() . '.csv', 'disk' => 'local', 'path' => $path,
            'data_type' => $type, 'status' => Import::STATUS_UPLOADED, 'total_rows' => substr_count(trim($csv), "\n"),
            'source' => Import::SOURCE_UPLOAD, 'feed_key' => Import::feedKeyFor(Import::SOURCE_UPLOAD, $type)]);
        foreach ($map as $h => $field) {
            $import->columnMaps()->create(['source_header' => $h, 'target_field' => $field, 'is_skipped' => false, 'is_confirmed' => true]);
        }

        return $import;
    }
}
