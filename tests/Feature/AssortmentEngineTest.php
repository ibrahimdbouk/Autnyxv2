<?php

namespace Tests\Feature;

use App\Models\AssortmentGap;
use App\Models\AssortmentMustStock;
use App\Models\AssortmentRun;
use App\Models\AssortmentStoreRange;
use App\Models\Tenant;
use App\Platform\Intelligence\Availability\AvailabilityService;
use App\Platform\Intelligence\Clustering\ClusterService;
use App\Services\Assortment\AssortmentEngine;
use App\Services\Assortment\RangeModel;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Assortment A1–A3 on a small, fully known estate: six supermarkets in one
 * region (one peer group), 200 days of daily sales and stock. Store 1 is the
 * store being judged on the planted products:
 *
 *   ADD-ME   carried and sold by stores 2–6, never at store 1        → add
 *   OUTAGE   sold everywhere; store 1 in stock only 40% of days       → stockout-hidden, never delist
 *   DUD      store 1 sells 1/40th of peers, always in stock           → delist
 *   SHIELD   like DUD but on the must-stock list                      → nothing
 *   BASE-n   ordinary sellers everywhere                               → nothing
 */
class AssortmentEngineTest extends TestCase
{
    private Tenant $tenant;

    /** @var array<int,int> store number => id */
    private array $stores = [];

    private string $asOf = '2026-09-20';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant([
            'apps' => [Tenant::APP_ROOT_CAUSE, Tenant::APP_ASSORTMENT],
            'currency' => 'AED',
        ]);
    }

    private function seedEstate(int $storeCount = 6, int $days = 200): void
    {
        $t = $this->tenant->id;
        for ($i = 1; $i <= $storeCount; $i++) {
            $this->stores[$i] = DB::table('stores')->insertGetId([
                'tenant_id' => $t, 'name' => "Store {$i}", 'code' => "S{$i}", 'format' => 'Supermarket', 'region' => 'North',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $skus = ['ADD-ME' => 'Greek yogurt', 'OUTAGE' => 'Oat milk', 'DUD' => 'Goat kefir', 'SHIELD' => 'Lactose-free cream'];
        for ($n = 1; $n <= 8; $n++) {
            $skus["BASE-{$n}"] = "Dairy item {$n}";
        }
        foreach ($skus as $sku => $name) {
            DB::table('products')->insert([
                'tenant_id' => $t, 'sku' => $sku, 'name' => $name, 'category' => 'Dairy', 'subcategory' => 'Chilled',
                'unit_cost' => 6, 'selling_price' => 10, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $sales = $stock = [];
        $end = Carbon::parse($this->asOf);
        for ($d = 0; $d < $days; $d++) {
            $date = $end->copy()->subDays($d)->toDateString();
            foreach ($this->stores as $num => $storeId) {
                foreach (array_keys($skus) as $sku) {
                    [$units, $onHand] = $this->plan($num, $sku, $d);
                    if ($units === null) {
                        continue;   // not carried at this store
                    }
                    $stock[] = ['tenant_id' => $t, 'store_id' => $storeId, 'sku' => $sku, 'on_hand_qty' => $onHand,
                        'as_of_date' => $date, 'created_at' => now(), 'updated_at' => now()];
                    if ($units > 0) {
                        $sales[] = ['tenant_id' => $t, 'store_id' => $storeId, 'sku' => $sku, 'date' => $date,
                            'units_sold' => $units, 'revenue' => $units * 10, 'transaction_count' => 1,
                            'created_at' => now(), 'updated_at' => now()];
                    }
                }
            }
        }
        foreach (array_chunk($sales, 1000) as $c) {
            DB::table('sales_daily')->insert($c);
        }
        foreach (array_chunk($stock, 1000) as $c) {
            DB::table('inventory_levels')->insert($c);
        }
        foreach ($this->stores as $storeId) {
            foreach (array_keys($skus) as $sku) {
                DB::table('inventory_current')->insert(['tenant_id' => $t, 'store_id' => $storeId, 'sku' => $sku,
                    'on_hand_qty' => 50, 'unit_cost' => 6, 'as_of_date' => $this->asOf, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        app(ClusterService::class)->rebuild($t, 'attribute');
    }

    /** [units sold that day, stock on hand] — units null = not carried. */
    private function plan(int $store, string $sku, int $day): array
    {
        return match (true) {
            $sku === 'ADD-ME' && $store === 1              => [null, 0],
            $sku === 'ADD-ME'                              => [4, 30],
            $sku === 'OUTAGE' && $store === 1              => $day % 5 < 2 ? [4, 20] : [0, 0],   // in stock 40% of days
            $sku === 'OUTAGE'                              => [4, 20],
            in_array($sku, ['DUD', 'SHIELD'], true) && $store === 1 => [$day % 20 === 0 ? 2 : 0, 40],   // 0.1/day
            in_array($sku, ['DUD', 'SHIELD'], true)        => [4, 40],
            default                                        => [2 + ((int) substr($sku, -1)) % 4, 25],
        };
    }

    private function gap(string $sku, string $type, int $store = 1): ?AssortmentGap
    {
        return AssortmentGap::where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->stores[$store])->where('sku', $sku)->where('type', $type)->first();
    }

    public function test_availability_is_the_share_of_observed_days_in_stock(): void
    {
        $this->seedEstate();
        $from = Carbon::parse($this->asOf)->subDays(89)->toDateString();

        $a = app(AvailabilityService::class)->forPosition($this->tenant->id, $this->stores[1], 'OUTAGE', $from, $this->asOf);
        $this->assertEqualsWithDelta(0.4, $a, 0.02);
        $this->assertSame(1.0, app(AvailabilityService::class)->forPosition($this->tenant->id, $this->stores[2], 'OUTAGE', $from, $this->asOf));
        $this->assertNull(app(AvailabilityService::class)->forPosition($this->tenant->id, $this->stores[1], 'ADD-ME', $from, $this->asOf));
    }

    public function test_the_range_is_rebuilt_from_sales_and_stock_history(): void
    {
        $this->seedEstate();
        $rm = app(RangeModel::class);

        $this->assertSame($this->asOf, $rm->asOfDate($this->tenant->id));
        $stats = $rm->rebuild($this->tenant->id, $this->asOf);

        $this->assertSame(6, $stats['stores']);
        $this->assertSame(200, $stats['history_days']);
        $this->assertFalse(AssortmentStoreRange::where('store_id', $this->stores[1])->where('sku', 'ADD-ME')->exists());
        $row = AssortmentStoreRange::where('store_id', $this->stores[2])->where('sku', 'ADD-ME')->first();
        $this->assertTrue($row->carried);
        $this->assertSame(AssortmentStoreRange::SOURCE_INFERRED, $row->source);
        $this->assertSame('2026-03-05', $row->first_seen->toDateString());
    }

    public function test_the_three_decisions_are_found_and_stored_in_shadow(): void
    {
        $this->seedEstate();
        $run = app(AssortmentEngine::class)->run($this->tenant);

        $this->assertSame(AssortmentRun::STATUS_SUCCESS, $run->status, json_encode($run->stats));

        $add = $this->gap('ADD-ME', AssortmentGap::TYPE_ADD);
        $this->assertNotNull($add, 'ADD-ME should be an add at store 1');
        $this->assertSame(AssortmentGap::STATUS_SHADOW, $add->status);
        $this->assertSame(5, $add->evidence['peers_carrying']);
        $this->assertTrue($add->value_low < $add->value_mid && $add->value_mid < $add->value_high, 'value is a range');
        $this->assertSame('margin', $add->evidence['value_basis']);
        $this->assertStringContainsString('Add Greek yogurt at Store 1', $add->explanation['headline']);

        $outage = $this->gap('OUTAGE', AssortmentGap::TYPE_STOCKOUT_HIDDEN);
        $this->assertNotNull($outage, 'OUTAGE should be stockout-hidden at store 1');
        $this->assertEqualsWithDelta(0.4, $outage->evidence['availability'], 0.02);
        $this->assertNull($this->gap('OUTAGE', AssortmentGap::TYPE_DELIST), 'a product that keeps running out is never a delist');

        $dud = $this->gap('DUD', AssortmentGap::TYPE_DELIST);
        $this->assertNotNull($dud, 'DUD should be a delist at store 1');
        $this->assertLessThan(0.3, $dud->evidence['ratio_to_peers']);
        $this->assertEquals(300.0, $dud->evidence['stock_value']);

        // Ordinary sellers and the peers themselves raise nothing.
        $this->assertSame(0, AssortmentGap::where('tenant_id', $this->tenant->id)->where('sku', 'like', 'BASE-%')->count());
        $this->assertSame(0, AssortmentGap::where('tenant_id', $this->tenant->id)->where('store_id', '<>', $this->stores[1])->count());
    }

    public function test_must_stock_products_are_never_proposed_for_delisting(): void
    {
        $this->seedEstate();
        AssortmentMustStock::create(['tenant_id' => $this->tenant->id, 'sku' => 'SHIELD', 'store_id' => null, 'reason' => 'Contract']);

        app(AssortmentEngine::class)->run($this->tenant);

        $this->assertNull($this->gap('SHIELD', AssortmentGap::TYPE_DELIST));
        $this->assertNotNull($this->gap('DUD', AssortmentGap::TYPE_DELIST));
    }

    public function test_delists_are_held_until_26_weeks_of_history(): void
    {
        $this->seedEstate(6, 120);

        $run = app(AssortmentEngine::class)->run($this->tenant);

        $this->assertFalse($run->stats['delists_allowed']);
        $this->assertNull($this->gap('DUD', AssortmentGap::TYPE_DELIST));
        $this->assertNotNull($this->gap('ADD-ME', AssortmentGap::TYPE_ADD), 'adds do not wait');
    }

    public function test_a_peer_group_that_is_too_small_judges_nobody(): void
    {
        $this->seedEstate(4);

        $run = app(AssortmentEngine::class)->run($this->tenant);

        $this->assertSame(4, $run->stats['stores_unjudged']);
        $this->assertSame(0, AssortmentGap::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_a_decision_not_found_again_is_removed_and_a_refound_one_keeps_its_first_date(): void
    {
        $this->seedEstate();
        app(AssortmentEngine::class)->run($this->tenant);
        $add = $this->gap('ADD-ME', AssortmentGap::TYPE_ADD);
        $add->forceFill(['first_detected_at' => now()->subDays(3)])->save();

        AssortmentGap::create(['tenant_id' => $this->tenant->id, 'store_id' => $this->stores[2], 'sku' => 'BASE-1',
            'type' => AssortmentGap::TYPE_ADD, 'status' => AssortmentGap::STATUS_SHADOW, 'peer_group' => 'x',
            'confidence_tier' => 'likely', 'as_of_date' => $this->asOf, 'last_detected_at' => now()->subDay()]);

        $this->travel(1)->minutes();
        app(AssortmentEngine::class)->run($this->tenant);

        $this->assertNull($this->gap('BASE-1', AssortmentGap::TYPE_ADD, 2), 'stale decision removed');
        $this->assertTrue($this->gap('ADD-ME', AssortmentGap::TYPE_ADD)->first_detected_at->lt(now()->subDays(2)));
    }

    public function test_tenants_without_the_app_are_skipped_unless_forced_or_flagged(): void
    {
        $other = $this->createTenant(['apps' => [Tenant::APP_ROOT_CAUSE]]);

        $this->assertFalse(AssortmentEngine::enabledFor($other));
        $this->assertSame(AssortmentRun::STATUS_SKIPPED, app(AssortmentEngine::class)->run($other)->status);

        $other->update(['settings' => ['assortment' => ['shadow_run' => true]]]);
        $this->assertTrue(AssortmentEngine::enabledFor($other->fresh()));
    }

    public function test_the_commands_run_and_list_for_review(): void
    {
        $this->seedEstate();

        $this->artisan('assortment:run', ['--tenant' => $this->tenant->id])->assertSuccessful();
        $this->artisan('assortment:review', ['--tenant' => $this->tenant->id, '--type' => 'add'])
            ->expectsOutputToContain('ADD-ME')
            ->assertSuccessful();
    }

    public function test_the_assortment_tab_shows_readiness_but_never_shadow_decisions(): void
    {
        $this->seedEstate();
        app(AssortmentEngine::class)->run($this->tenant);

        $this->actingAsTenantAdmin($this->tenant);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        \Filament\Facades\Filament::setTenant($this->tenant);

        $this->get(\App\Filament\Pages\Dashboard::getUrl(['app' => Tenant::APP_ASSORTMENT], tenant: $this->tenant))
            ->assertOk()
            ->assertSee('Range data readiness')
            ->assertSee('28 weeks')
            ->assertSee('Range decisions are being checked')
            ->assertDontSee('Greek yogurt')
            ->assertDontSee('Goat kefir');
    }
}
