<?php

namespace Tests\Feature;

use App\Filament\Pages\PerformanceDrivers;
use App\Models\Anomaly;
use App\Models\ApiKey;
use App\Models\Import;
use App\Models\Investigation;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Anomaly\EvidenceCollectorService;
use App\Services\Anomaly\RootCauseAnalysisService;
use App\Services\Drivers\DriverReports;
use App\Services\Import\ImportProcessorService;
use App\Services\Sales\SalesDailyAggregator;
use App\Support\Testing\DriverBenchmark;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Root Cause drivers — the data foundation (revenue for lines without an
 * amount, store and category rollups, the store traffic import) and the
 * places the breakdown shows up (findings, cause chain, page, briefing, API).
 */
class SalesDriversPlatformTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-08 06:00'));
        $this->tenant = $this->createTenant(['currency' => 'AED']);
    }

    private function import(string $type, string $csv, array $map): Import
    {
        Storage::fake('local');
        Queue::fake();
        $admin = $this->createUser($this->tenant, admin: true);
        $path = 'imports/pending/d-' . uniqid() . '.csv';
        Storage::disk('local')->put($path, $csv);
        $import = Import::create(['tenant_id' => $this->tenant->id, 'user_id' => $admin->id, 'original_filename' => "{$type}.csv", 'disk' => 'local', 'path' => $path,
            'data_type' => $type, 'status' => Import::STATUS_UPLOADED, 'total_rows' => substr_count(trim($csv), "\n"),
            'source' => Import::SOURCE_UPLOAD, 'feed_key' => Import::feedKeyFor(Import::SOURCE_UPLOAD, $type)]);
        foreach ($map as $h => $f) {
            $import->columnMaps()->create(['source_header' => $h, 'target_field' => $f, 'is_skipped' => false, 'is_confirmed' => true]);
        }
        $svc = app(ImportProcessorService::class);
        $svc->startChunkedImport($import);
        $guard = 0;
        do {
            $r = $svc->processChunk($import->fresh(), 100);
        } while (! ($r['done'] ?? false) && ++$guard < 20);

        return $import->fresh();
    }

    public function test_a_sales_file_without_amounts_is_valued_and_rolled_up_by_store_and_category(): void
    {
        $store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Marina', 'code' => 'MAR']);
        DB::table('products')->insert(['tenant_id' => $this->tenant->id, 'sku' => 'A', 'name' => 'Apple', 'department' => 'Fresh', 'category' => 'Fruit', 'created_at' => now(), 'updated_at' => now()]);
        $csv = "Date,Store,SKU,Qty,Price,Discount,Receipt\n2026-09-01,MAR,A,2,5,1,R1\n2026-09-01,MAR,B,1,10,,R1\n2026-09-01,MAR,A,1,5,,R2\n";
        $this->import(Import::TYPE_SALES, $csv, ['Date' => 'date', 'Store' => 'location', 'SKU' => 'sku', 'Qty' => 'quantity', 'Price' => 'unit_price',
            'Discount' => 'discount', 'Receipt' => 'transaction_id']);

        $this->assertSame([9.0, 10.0, 5.0], DB::table('sales_transactions')->where('tenant_id', $this->tenant->id)->orderBy('id')->pluck('total_amount')->map(fn ($v) => (float) $v)->all(),
            'quantity × price − discount');
        $this->assertSame(24.0, (float) DB::table('sales_daily')->where('tenant_id', $this->tenant->id)->sum('revenue'));

        $day = DB::table('store_daily')->where('tenant_id', $this->tenant->id)->where('store_id', $store->id)->sole();
        $this->assertSame([2, 3, 3, 4.0, 24.0, 2], [(int) $day->receipts, (int) $day->lines, (int) $day->lines_with_receipt, (float) $day->units, (float) $day->revenue, (int) $day->skus_sold]);
        $cats = DB::table('category_daily')->where('tenant_id', $this->tenant->id)->orderBy('department')->get()->keyBy('category');
        $this->assertSame(2, (int) $cats['Fruit']->receipts, 'two receipts contain fruit');
        $this->assertSame('', $cats['']->department, 'an unknown product is unassigned');

        // Re-classified by a product file: the rollup follows.
        DB::table('products')->where('tenant_id', $this->tenant->id)->where('sku', 'A')->update(['category' => 'Apples']);
        (new \App\Jobs\Sales\RebuildSalesRollupsJob($this->tenant->id))->handle(app(\App\Services\Sales\SalesRollupAggregator::class));
        $this->assertTrue(DB::table('category_daily')->where('tenant_id', $this->tenant->id)->where('category', 'Apples')->exists());

        // Lines already loaded without an amount get theirs from the backfill.
        DB::table('sales_transactions')->where('tenant_id', $this->tenant->id)->update(['total_amount' => null]);
        $this->artisan('sales:rollups', ['--tenant' => $this->tenant->id, '--derive-revenue' => true])->assertSuccessful();
        $this->assertSame(24.0, (float) DB::table('store_daily')->where('tenant_id', $this->tenant->id)->sum('revenue'));
    }

    public function test_the_store_traffic_import_adds_counters_and_replaces_a_resent_day(): void
    {
        $store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Marina', 'code' => 'MAR']);
        $map = ['Date' => 'date', 'Store' => 'location', 'Footfall' => 'visitors'];
        $this->import(Import::TYPE_STORE_TRAFFIC, "Date,Store,Footfall\n2026-09-01,MAR,120\n2026-09-01,mar,30\n2026-09-02,Marina,90\n", $map);
        $this->assertSame([150.0, 90.0], DB::table('store_traffic')->where('tenant_id', $this->tenant->id)->where('store_id', $store->id)->orderBy('date')->pluck('visitors')->map(fn ($v) => (float) $v)->all(),
            'two counters of one store on one day add up');

        $import = $this->import(Import::TYPE_STORE_TRAFFIC, "Date,Store,Footfall\n2026-09-01,MAR,140\n", $map);
        $this->assertSame(140.0, (float) DB::table('store_traffic')->where('tenant_id', $this->tenant->id)->where('date', '2026-09-01')->value('visitors'), 'a re-sent day replaces');
        $this->assertSame('visitors', app(\App\Services\Import\ColumnMappingService::class)->map(['Footfall'], [], Import::TYPE_STORE_TRAFFIC, $this->tenant->id)[0]['target_field'] ?? null);
        $this->assertContains(Import::TYPE_STORE_TRAFFIC, Import::ROLLBACK_TYPES);
        $this->assertNotNull($import->id);
    }

    public function test_findings_carry_their_drivers_into_evidence_the_cause_chain_and_the_ranking(): void
    {
        app(DriverBenchmark::class)->run($this->tenant->id);
        $store = Store::where('tenant_id', $this->tenant->id)->where('code', 'BM3')->sole();   // the out-of-stock store
        $inv = Investigation::factory()->create(['tenant_id' => $this->tenant->id, 'status' => Investigation::STATUS_OPEN, 'primary_sku' => 'P2']);
        $drop = Anomaly::create(['tenant_id' => $this->tenant->id, 'investigation_id' => $inv->id, 'rule_type' => 'sales_drop', 'severity' => 'high', 'sku' => 'P2',
            'store_id' => $store->id, 'description' => 'P2 down', 'detected_at' => now(),
            'context' => ['window' => [DriverBenchmark::P_FROM, DriverBenchmark::P_TO], 'days' => 7, 'revenue_impact' => 240]]);
        Anomaly::create(['tenant_id' => $this->tenant->id, 'investigation_id' => $inv->id, 'rule_type' => 'stockout_risk', 'severity' => 'high', 'sku' => 'P2',
            'store_id' => $store->id, 'description' => 'P2 out', 'detected_at' => now(), 'context' => ['revenue_impact' => 240]]);

        app(EvidenceCollectorService::class)->collectForInvestigation($inv);
        $ev = DB::table('investigation_evidence')->where('investigation_id', $inv->id)->where('label', EvidenceCollectorService::DRIVER_LABEL)->sole();
        $this->assertSame($drop->id, (int) $ev->anomaly_id);
        $this->assertStringContainsString('out of stock −', $ev->value_text);
        $this->assertSame('availability', $inv->fresh()->drivers_explained);

        $cause = app(RootCauseAnalysisService::class)->analyze($inv->fresh());
        $this->assertTrue($cause['drivers_confirmed']);
        $this->assertStringContainsString('The sales data confirms it', $cause['explanation']);

        // The investigation page shows the breakdown.
        $this->actingAsTenantAdmin($this->tenant);
        $this->get(\App\Filament\Resources\InvestigationResource::getUrl('investigate', ['record' => $inv, 'tenant' => $this->tenant]))
            ->assertOk()->assertSee('What moved the sales, and why')->assertSee('Mostly out of stock');
    }

    public function test_the_page_the_briefing_and_the_api_show_the_weekly_breakdown(): void
    {
        app(DriverBenchmark::class)->run($this->tenant->id);
        $this->artisan('drivers:compute', ['--tenant' => $this->tenant->id])->assertSuccessful();
        $mains = collect(app(DriverReports::class)->latestWeekly($this->tenant->id)->result['contributors']['stores'])->pluck('main', 'name');
        $this->assertSame(['store_traffic', 'price', 'availability', 'promotions', 'range'],
            [$mains['Bench fewer shoppers'], $mains['Bench price rise'], $mains['Bench out of stock'], $mains['Bench promotion end'], $mains['Bench launch']]);

        $this->actingAsTenantAdmin($this->tenant);
        $this->get(PerformanceDrivers::getUrl(['tenant' => $this->tenant]))->assertOk()
            ->assertSee('What moved')->assertSee('Why it moved')->assertSee('Where it came from')->assertSee('Out of stock');
        $store = Store::where('tenant_id', $this->tenant->id)->where('code', 'BM1')->sole();
        $this->get(PerformanceDrivers::getUrl(['tenant' => $this->tenant, 'filters' => ['store' => $store->id]]))->assertOk()->assertSee('Traffic (receipts)');

        \Filament\Facades\Filament::setTenant(null, isQuiet: true);
        $stats = app(\App\Services\Agents\WeeklyBriefingAgent::class)->weeklyStats($this->tenant->id);
        $this->assertNotNull($stats['sales_drivers']);
        $this->get(\App\Filament\Pages\WeeklyBriefing::getUrl(['tenant' => $this->tenant]))->assertOk()->assertSee('What moved sales and why');

        [, $token] = ApiKey::generate($this->tenant->id, 'bi', [ApiKey::SCOPE_READ_EXPORTS]);
        $rows = $this->getJson('/api/v1/exports/drivers_weekly', ['X-Api-Key' => $token])->assertOk()->json('data');
        $business = collect($rows)->firstWhere('scope', 'business');
        $parts = ['traffic', 'basket', 'mix', 'price'];
        $this->assertEqualsWithDelta($business['change'], array_sum(array_map(fn ($k) => (float) $business[$k], $parts)), 0.01);
        $this->getJson('/api/v1/exports/store_daily?limit=2', ['X-Api-Key' => $token])->assertOk()->assertJsonCount(2, 'data');

        // Another tenant sees none of it (a key is the panel's tenant's while one is set).
        \Filament\Facades\Filament::setTenant(null, isQuiet: true);
        $other = $this->createTenant();
        [, $otherToken] = ApiKey::generate($other->id, 'bi', [ApiKey::SCOPE_READ_EXPORTS]);
        $this->getJson('/api/v1/exports/drivers_weekly', ['X-Api-Key' => $otherToken])->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_an_empty_tenant_gets_an_empty_page_not_an_error(): void
    {
        $this->actingAsTenantAdmin($this->tenant);
        $this->get(PerformanceDrivers::getUrl(['tenant' => $this->tenant]))->assertOk()->assertSee('No sales to compare yet');
        $this->assertNull(app(DriverReports::class)->weeklyPeriods($this->tenant->id));
    }
}
