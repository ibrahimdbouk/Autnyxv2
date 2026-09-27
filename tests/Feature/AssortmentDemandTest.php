<?php

namespace Tests\Feature;

use App\Models\AssortmentGap;
use App\Models\Tenant;
use App\Platform\Intelligence\Availability\AvailabilityService;
use App\Platform\Intelligence\Lifecycle\ProductLifecycleService;
use App\Platform\Intelligence\Promotions\PromotionCalendar;
use App\Platform\Intelligence\Substitution\DemandTransferService;
use App\Platform\Intelligence\Substitution\ProductSimilarity;
use App\Platform\Intelligence\Substitution\TransferEstimator;
use App\Services\Assortment\AssortmentEngine;
use App\Services\Assortment\GroupData;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\SeedsAssortmentEstate;
use Tests\TestCase;

/**
 * v1.5 Phase 0 + 1 — demand that is fit to judge ranges with:
 *   promotion-clean rates, product lifecycle, and transferable demand
 *   (where a product's buyers go when it is not on the shelf), observed from
 *   stockout days and range changes, or assumed from product similarity — and
 *   what that does to adds, delists and stockout-hidden decisions.
 */
class AssortmentDemandTest extends TestCase
{
    use SeedsAssortmentEstate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant(['apps' => [Tenant::APP_ROOT_CAUSE, Tenant::APP_ASSORTMENT], 'currency' => 'AED']);
    }

    private function gap(string $sku, string $type, int $store = 1): ?AssortmentGap
    {
        return AssortmentGap::where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->stores[$store])->where('sku', $sku)->where('type', $type)->first();
    }

    private function products(): array
    {
        $out = [];
        foreach (DB::table('products')->where('tenant_id', $this->tenant->id)->get() as $p) {
            $out[$p->sku] = ['id' => $p->id, 'name' => $p->name, 'category' => $p->category, 'subcategory' => $p->subcategory,
                'brand' => $p->brand, 'pack_size' => $p->pack_size, 'price' => (float) $p->selling_price, 'cost' => (float) $p->unit_cost,
                'status' => $p->status, 'season' => $p->season];
        }

        return $out;
    }

    // ── Promotion-clean demand ────────────────────────────────────────────────

    public function test_promotion_days_are_left_out_of_a_stores_rate(): void
    {
        $this->seedEstate();
        $t = $this->tenant->id;
        $from = Carbon::parse($this->asOf)->subDays(29)->toDateString();
        // Store 2 sold ADD-ME ten times its usual on a 30-day promotion.
        DB::table('sales_daily')->where('tenant_id', $t)->where('store_id', $this->stores[2])->where('sku', 'ADD-ME')
            ->whereBetween('date', [$from, $this->asOf])->update(['units_sold' => 40, 'revenue' => 400]);
        DB::table('promotions')->insert(['tenant_id' => $t, 'promotion_ref' => 'BIG', 'sku' => 'ADD-ME', 'store_id' => $this->stores[2],
            'starts_on' => $from, 'ends_on' => $this->asOf, 'created_at' => now(), 'updated_at' => now()]);

        app(\App\Services\Assortment\RangeModel::class)->rebuild($t, $this->asOf);
        $members = array_values($this->stores);
        $catRev = GroupData::categoryRevenue($t, $members, $this->asOf);
        $read = fn (?PromotionCalendar $cal) => (new GroupData($t, $members, $this->asOf, $this->products(), $catRev, ['ADD-ME'], $cal))
            ->load(app(AvailabilityService::class))->perf[$this->stores[2]]['ADD-ME'];

        $raw = $read(null);
        $clean = $read(PromotionCalendar::load($t, Carbon::parse($this->asOf)->subDays(400)->toDateString(), $this->asOf));

        $this->assertGreaterThan(10, $raw['units_per_day'], 'without the calendar the promotion reads as demand');
        $this->assertEqualsWithDelta(4.0, $clean['units_per_day'], 0.01, 'promotion days are left out');
        $this->assertSame(30, $clean['promo_days']);
        $this->assertSame(60, $clean['clean_days']);
        $this->assertGreaterThan(0.7, $clean['promo_share']);
    }

    public function test_without_any_promotion_data_decisions_are_one_tier_less_sure(): void
    {
        $this->withPromotionData = false;
        $this->seedEstate();

        $run = app(AssortmentEngine::class)->run($this->tenant);

        $this->assertFalse($run->stats['promotion_data']);
        $add = $this->gap('ADD-ME', AssortmentGap::TYPE_ADD);
        $this->assertSame(AssortmentGap::TIER_SPECULATIVE, $add->confidence_tier, '5 peers is "likely"; one tier lower without promotion data');
        $this->assertStringContainsString('No promotion data', implode(' ', $add->explanation['reasons'] ?? []));
        $this->assertNull($this->gap('DUD', AssortmentGap::TYPE_DELIST), 'a delist needs "likely" or better');
    }

    // ── Lifecycle ─────────────────────────────────────────────────────────────

    public function test_lifecycle_states_are_read_from_the_history(): void
    {
        $t = $this->tenant->id;
        $asOf = Carbon::parse('2026-09-20');
        $stores = [];
        for ($i = 1; $i <= 3; $i++) {
            $stores[$i] = DB::table('stores')->insertGetId(['tenant_id' => $t, 'name' => "S{$i}", 'code' => "S{$i}", 'created_at' => now(), 'updated_at' => now()]);
        }
        $plans = [
            'OLD'    => fn (int $d) => 5,                                   // 200 days, steady → established
            'FRESH'  => fn (int $d) => $d < 40 ? 5 : 0,                     // first sold 40 days ago → new
            'RISING' => fn (int $d) => $d < 150 ? ($d < 42 ? 8 : 4) : 0,    // 150 days old, last 6 weeks doubled → emerging
            'FADING' => fn (int $d) => $d < 91 ? 1 : 6,                     // last 13 weeks a sixth of before → declining
            'GONE'   => fn (int $d) => $d < 70 ? 0 : 5,                     // nothing for 10 weeks → end of life
            'XMAS'   => fn (int $d) => 5,                                   // season on the product file → seasonal
        ];
        foreach (array_keys($plans) as $sku) {
            DB::table('products')->insert(['tenant_id' => $t, 'sku' => $sku, 'name' => $sku, 'category' => 'Food',
                'season' => $sku === 'XMAS' ? 'Christmas' : null, 'created_at' => now(), 'updated_at' => now()]);
        }
        $rows = [];
        for ($d = 0; $d < 200; $d++) {
            foreach ($plans as $sku => $plan) {
                foreach ($stores as $st) {
                    if (($u = $plan($d)) > 0) {
                        $rows[] = ['tenant_id' => $t, 'store_id' => $st, 'sku' => $sku, 'date' => $asOf->copy()->subDays($d)->toDateString(),
                            'units_sold' => $u, 'revenue' => $u * 10, 'transaction_count' => 1, 'created_at' => now(), 'updated_at' => now()];
                    }
                }
            }
        }
        foreach (array_chunk($rows, 1000) as $c) {
            DB::table('sales_daily')->insert($c);
        }

        $svc = app(ProductLifecycleService::class);
        $counts = $svc->rebuild($t, $asOf->toDateString());
        $chain = $svc->chain($t);

        $this->assertSame('established', $chain['OLD']['state']);
        $this->assertSame('new', $chain['FRESH']['state']);
        $this->assertSame('emerging', $chain['RISING']['state']);
        $this->assertSame('declining', $chain['FADING']['state']);
        $this->assertSame('end_of_life', $chain['GONE']['state']);
        $this->assertSame('seasonal', $chain['XMAS']['state']);
        $this->assertStringContainsString('Christmas', (string) $chain['XMAS']['reason']);
        $this->assertSame(18, $counts['store_rows']);
        $this->assertSame('new', $svc->at($t, $stores[1], 'FRESH')['state']);
    }

    public function test_a_product_selling_since_the_data_began_is_not_new_however_young_the_data(): void
    {
        $t = $this->tenant->id;
        $st = DB::table('stores')->insertGetId(['tenant_id' => $t, 'name' => 'Y', 'code' => 'Y', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('products')->insert(['tenant_id' => $t, 'sku' => 'ALWAYS', 'name' => 'Always', 'category' => 'Food', 'created_at' => now(), 'updated_at' => now()]);
        $rows = [];
        for ($d = 0; $d < 50; $d++) {
            $rows[] = ['tenant_id' => $t, 'store_id' => $st, 'sku' => 'ALWAYS', 'date' => Carbon::parse('2026-09-20')->subDays($d)->toDateString(),
                'units_sold' => 5, 'revenue' => 50, 'transaction_count' => 1, 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('sales_daily')->insert($rows);

        app(ProductLifecycleService::class)->rebuild($t, '2026-09-20');

        $this->assertSame('established', app(ProductLifecycleService::class)->chain($t)['ALWAYS']['state'], 'older than the data, not new');
    }

    public function test_a_new_product_is_never_an_add_and_a_new_one_never_a_delist(): void
    {
        $this->seedEstate();
        $t = $this->tenant->id;
        // NEWBIE: launched 40 days ago at stores 2–6, selling well; not at store 1.
        DB::table('products')->insert(['tenant_id' => $t, 'sku' => 'NEWBIE', 'name' => 'New drink', 'category' => 'Dairy', 'subcategory' => 'Chilled',
            'unit_cost' => 6, 'selling_price' => 10, 'created_at' => now(), 'updated_at' => now()]);
        $rows = $stock = [];
        for ($d = 0; $d < 40; $d++) {
            foreach ([2, 3, 4, 5, 6] as $n) {
                $date = Carbon::parse($this->asOf)->subDays($d)->toDateString();
                $rows[] = ['tenant_id' => $t, 'store_id' => $this->stores[$n], 'sku' => 'NEWBIE', 'date' => $date,
                    'units_sold' => 6, 'revenue' => 60, 'transaction_count' => 1, 'created_at' => now(), 'updated_at' => now()];
                $stock[] = ['tenant_id' => $t, 'store_id' => $this->stores[$n], 'sku' => 'NEWBIE', 'on_hand_qty' => 30,
                    'as_of_date' => $date, 'created_at' => now(), 'updated_at' => now()];
            }
        }
        DB::table('sales_daily')->insert($rows);
        DB::table('inventory_levels')->insert($stock);

        $run = app(AssortmentEngine::class)->run($this->tenant);

        $this->assertSame('new', DB::table('product_lifecycles')->where('tenant_id', $t)->whereNull('store_id')->where('sku', 'NEWBIE')->value('state'));
        $this->assertGreaterThanOrEqual(1, $run->stats['lifecycle']['new']);
        $this->assertNull($this->gap('NEWBIE', AssortmentGap::TYPE_ADD), 'too new to judge');
        $this->assertGreaterThanOrEqual(1, $run->stats['decisions']['skipped_lifecycle']);
        $this->assertNotNull($this->gap('ADD-ME', AssortmentGap::TYPE_ADD), 'established products are judged as before');
        $this->assertSame('established', $this->gap('ADD-ME', AssortmentGap::TYPE_ADD)->evidence['lifecycle']);
    }

    // ── Transferable demand: observed ─────────────────────────────────────────

    /**
     * Six stores, one category with two subcategories. In "Cola": ZERO, DIET
     * and REGULAR sell 10 a day. ZERO is out of stock all day every 7th day; on
     * those days DIET sells 16 (60% of ZERO's buyers) and REGULAR 11 (10%).
     * "Juice" is the rest of the category, steady — the day index.
     */
    private function seedColaEstate(bool $withBrands = true): array
    {
        $t = $this->tenant->id;
        $stores = [];
        for ($i = 1; $i <= 6; $i++) {
            $stores[$i] = DB::table('stores')->insertGetId(['tenant_id' => $t, 'name' => "Store {$i}", 'code' => "C{$i}",
                'format' => 'Supermarket', 'region' => 'North', 'created_at' => now(), 'updated_at' => now()]);
        }
        $defs = [
            'ZERO'    => ['Cola zero 500ml', 'Cola', 'Fizz', '500ml'],
            'DIET'    => ['Diet cola 500ml', 'Cola', 'Fizz', '500ml'],
            'REGULAR' => ['Regular cola 1.5L', 'Cola', 'Other', '1.5L'],
            'LONER'   => ['Tonic', 'Mixers', null, null],
            'JUICE1'  => ['Orange juice', 'Juice', null, null],
            'JUICE2'  => ['Apple juice', 'Juice', null, null],
        ];
        foreach ($defs as $sku => [$name, $sub, $brand, $pack]) {
            DB::table('products')->insert(['tenant_id' => $t, 'sku' => $sku, 'name' => $name, 'category' => 'Drinks', 'subcategory' => $sub,
                'brand' => $withBrands ? $brand : null, 'pack_size' => $withBrands ? $pack : null,
                'unit_cost' => 6, 'selling_price' => 10, 'created_at' => now(), 'updated_at' => now()]);
        }
        $end = Carbon::parse($this->asOf);
        $sales = $stock = [];
        for ($d = 0; $d < 120; $d++) {
            $out = $d % 7 === 3;
            $date = $end->copy()->subDays($d)->toDateString();
            foreach ($stores as $st) {
                $plan = ['ZERO' => $out ? 0 : 10, 'DIET' => $out ? 16 : 10, 'REGULAR' => $out ? 11 : 10, 'LONER' => 5, 'JUICE1' => 8, 'JUICE2' => 8];
                foreach ($plan as $sku => $u) {
                    $stock[] = ['tenant_id' => $t, 'store_id' => $st, 'sku' => $sku, 'on_hand_qty' => ($sku === 'ZERO' && $out) ? 0 : 40,
                        'as_of_date' => $date, 'created_at' => now(), 'updated_at' => now()];
                    if ($u > 0) {
                        $sales[] = ['tenant_id' => $t, 'store_id' => $st, 'sku' => $sku, 'date' => $date, 'units_sold' => $u,
                            'revenue' => $u * 10, 'transaction_count' => 1, 'created_at' => now(), 'updated_at' => now()];
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

        return $stores;
    }

    public function test_stockout_days_show_where_a_products_buyers_go(): void
    {
        $stores = $this->seedColaEstate();
        $t = $this->tenant->id;
        $from = Carbon::parse($this->asOf)->subDays(119)->toDateString();

        $stats = app(DemandTransferService::class)->rebuild($t, 'pool:test', array_values($stores), $from, $this->asOf);
        $obs = app(DemandTransferService::class)->observed($t, 'pool:test', ['ZERO', 'DIET', 'LONER']);

        $this->assertGreaterThan(0, $stats['from_stockouts']);
        $this->assertEqualsWithDelta(0.60, $obs['ZERO']['DIET']['share'], 0.02, 'strong substitute');
        $this->assertEqualsWithDelta(0.10, $obs['ZERO']['REGULAR']['share'], 0.02, 'weak substitute');
        $this->assertSame('stockouts', $obs['ZERO']['DIET']['basis']);
        $this->assertSame(6, $obs['ZERO']['DIET']['stores']);
        $this->assertGreaterThanOrEqual(6 * 17, $obs['ZERO']['DIET']['store_days']);
        $this->assertSame(DemandTransferService::VERSION, $obs['ZERO']['DIET']['version']);
        $this->assertArrayNotHasKey('DIET', $obs, 'DIET never ran out: nothing observed out of it');
        $this->assertArrayNotHasKey('JUICE1', $obs['ZERO'] ?? [], 'pairs stay inside the subcategory');

        // A lone product: nothing like it on the shelf, none of its demand moves.
        $est = (new TransferEstimator())->estimate('LONER', ['ZERO', 'DIET', 'REGULAR', 'JUICE1'], $this->productsLite());
        $this->assertSame(0.0, $est['mid']);
    }

    public function test_range_changes_show_where_demand_came_from_and_went(): void
    {
        $t = $this->tenant->id;
        $stores = [];
        for ($i = 1; $i <= 6; $i++) {
            $stores[$i] = DB::table('stores')->insertGetId(['tenant_id' => $t, 'name' => "R{$i}", 'code' => "R{$i}", 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (['NEWCOLA', 'OLDCOLA', 'DIET'] as $sku) {
            DB::table('products')->insert(['tenant_id' => $t, 'sku' => $sku, 'name' => $sku, 'category' => 'Drinks', 'subcategory' => 'Cola',
                'selling_price' => 10, 'created_at' => now(), 'updated_at' => now()]);
        }
        // NEWCOLA launches at stores 1–3 60 days ago (10 a day), taking 4 a day from DIET there (40%).
        // OLDCOLA leaves stores 4–6 150 days ago (it sold 10 a day); DIET there gains 5 a day (50%).
        $end = Carbon::parse($this->asOf);
        $rows = [];
        for ($d = 0; $d < 200; $d++) {
            foreach ($stores as $n => $st) {
                $plan = $n <= 3
                    ? ['NEWCOLA' => $d < 60 ? 10 : 0, 'DIET' => $d < 60 ? 6 : 10]
                    : ['OLDCOLA' => $d >= 150 ? 10 : 0, 'DIET' => $d >= 150 ? 10 : 15];
                foreach ($plan as $sku => $u) {
                    if ($u > 0) {
                        $rows[] = ['tenant_id' => $t, 'store_id' => $st, 'sku' => $sku, 'date' => $end->copy()->subDays($d)->toDateString(),
                            'units_sold' => $u, 'revenue' => $u * 10, 'transaction_count' => 1, 'created_at' => now(), 'updated_at' => now()];
                    }
                }
            }
        }
        foreach (array_chunk($rows, 1000) as $c) {
            DB::table('sales_daily')->insert($c);
        }

        $stats = app(DemandTransferService::class)->rebuild($t, 'pool:rc', array_values($stores), $end->copy()->subDays(181)->toDateString(), $this->asOf);
        $obs = app(DemandTransferService::class)->observed($t, 'pool:rc', ['NEWCOLA', 'OLDCOLA']);

        $this->assertSame(2, $stats['from_range_changes']);
        $this->assertSame('range_changes', $obs['NEWCOLA']['DIET']['basis']);
        $this->assertEqualsWithDelta(0.40, $obs['NEWCOLA']['DIET']['share'], 0.03, 'launch: the share of its sales taken from DIET');
        $this->assertSame(3, $obs['NEWCOLA']['DIET']['events']);
        $this->assertEqualsWithDelta(0.50, $obs['OLDCOLA']['DIET']['share'], 0.03, 'exit: the share of its buyers who moved to DIET');
    }

    public function test_the_estimate_blends_evidence_with_the_assumption_by_sample_size(): void
    {
        $stores = $this->seedColaEstate();
        $t = $this->tenant->id;
        app(DemandTransferService::class)->rebuild($t, 'pool:test', array_values($stores), Carbon::parse($this->asOf)->subDays(119)->toDateString(), $this->asOf);
        $observed = app(DemandTransferService::class)->observed($t, 'pool:test', ['ZERO'])['ZERO'];
        $products = $this->productsLite();

        $est = (new TransferEstimator())->estimate('ZERO', ['DIET', 'REGULAR'], $products, $observed);
        $this->assertSame('observed', $est['basis']);
        $this->assertEqualsWithDelta(0.70, $est['mid'], 0.05, '60% + 10%, lightly pulled towards the assumption');
        $this->assertLessThanOrEqual($est['high'], $est['mid']);
        $this->assertGreaterThanOrEqual(0.0, $est['low']);
        $this->assertLessThanOrEqual($est['mid'], $est['low']);
        $this->assertSame('DIET', $est['pairs'][0]['sku']);

        // The same product with no observation falls back to the stated assumption.
        $assumed = (new TransferEstimator())->estimate('ZERO', ['DIET', 'REGULAR'], $products);
        $this->assertSame('assumed', $assumed['basis']);
        $this->assertContains('brand', $assumed['pairs'][0]['shared']);

        // No evidence and nothing on the product file to judge similarity by: no number is invented.
        $bare = $products;
        $bare['ZERO']['subcategory'] = null;
        $bare['ZERO']['brand'] = null;
        $none = (new TransferEstimator())->estimate('ZERO', ['DIET', 'REGULAR'], $bare);
        $this->assertSame('insufficient', $none['basis']);
        $this->assertNull($none['mid']);
    }

    public function test_similarity_reads_the_product_file(): void
    {
        $p = $this->productsLiteFrom([
            'A' => ['Drinks', 'Cola', 'Fizz', '6x330ml', 10.0],
            'B' => ['Drinks', 'Cola', 'Fizz', '2L', 10.5],
            'C' => ['Drinks', 'Juice', 'Fizz', '2L', 10.0],
        ]);
        $this->assertEqualsWithDelta(1.0, ProductSimilarity::closeness($p['A'], $p['B']), 0.001, 'same subcategory, brand, pack (1,980 vs 2,000 ml) and price');
        $this->assertSame(0.0, ProductSimilarity::closeness($p['A'], $p['C']), 'another subcategory is not a substitute');
        $this->assertSame([1980.0, 'ml'], ProductSimilarity::pack('6x330ml'));
        $this->assertSame([1500.0, 'ml'], ProductSimilarity::pack('1.5 L'));
        $this->assertNull(ProductSimilarity::pack('large'));
    }

    // ── Cannibalisation on the decisions ──────────────────────────────────────

    public function test_an_add_is_worth_less_when_similar_products_are_already_on_the_shelf(): void
    {
        $this->seedEstate();
        app(AssortmentEngine::class)->run($this->tenant);
        $crowded = $this->gap('ADD-ME', AssortmentGap::TYPE_ADD);

        // The same product in a subcategory of its own: nothing on store 1's shelf is like it.
        DB::table('products')->where('tenant_id', $this->tenant->id)->where('sku', 'ADD-ME')->update(['subcategory' => 'Yogurt']);
        app(AssortmentEngine::class)->run($this->tenant->fresh());
        $alone = $this->gap('ADD-ME', AssortmentGap::TYPE_ADD);

        $e = $crowded->evidence;
        $this->assertSame('assumed', $e['transfer']['basis']);
        $this->assertGreaterThan(0.5, $e['transfer']['share'][1], 'ten similar products: most of its sales would be taken from them');
        $this->assertNotEmpty($e['transfer']['pairs']);
        $this->assertEqualsWithDelta($e['gross_per_year'] * (1 - $e['transfer']['share'][1]), $crowded->value_mid, 1.0);
        $this->assertStringContainsString('would come from products already on the shelf', implode(' ', $crowded->explanation['evidence']));

        $this->assertEquals(0.0, $alone->evidence['transfer']['share'][1]);
        $this->assertEqualsWithDelta($alone->evidence['gross_per_year'], $alone->value_mid, 1.0, 'all new to the store');
        $this->assertGreaterThan($crowded->value_mid * 2, $alone->value_mid);
    }

    public function test_a_delist_loses_only_what_does_not_move_and_a_unique_product_is_kept(): void
    {
        $this->seedEstate();
        app(AssortmentEngine::class)->run($this->tenant);
        $dud = $this->gap('DUD', AssortmentGap::TYPE_DELIST);

        $e = $dud->evidence;
        $this->assertGreaterThan(0.5, $e['transfer']['share'][1], 'strong substitutes on the shelf');
        $this->assertEqualsWithDelta($e['current_per_year'] * (1 - $e['transfer']['share'][2]), $e['lost_per_year'][0], 1.0);
        $this->assertLessThan($e['current_per_year'], $e['lost_per_year'][1]);
        $this->assertLessThan(0, $e['expected_sales_change_per_year'], 'category sales are expected to fall a little');

        // Alone in its subcategory, it is the only product of its kind: never proposed.
        DB::table('products')->where('tenant_id', $this->tenant->id)->where('sku', 'DUD')->update(['subcategory' => 'Kefir']);
        app(AssortmentEngine::class)->run($this->tenant->fresh());
        $this->assertNull($this->gap('DUD', AssortmentGap::TYPE_DELIST));
    }

    public function test_a_stockout_costs_only_the_buyers_who_buy_nothing_instead(): void
    {
        $this->seedEstate();
        app(AssortmentEngine::class)->run($this->tenant);
        $out = $this->gap('OUTAGE', AssortmentGap::TYPE_STOCKOUT_HIDDEN);

        $e = $out->evidence;
        $this->assertNotNull($e['moved_per_year']);
        $this->assertLessThan($e['gross_per_year'], $out->value_mid);
        $this->assertGreaterThan(0, $e['expected_sales_change_per_year']);
    }

    public function test_observed_transfers_reach_the_decisions_through_the_run(): void
    {
        $this->seedColaEstate();
        $run = app(AssortmentEngine::class)->run($this->tenant);

        $this->assertGreaterThan(0, $run->stats['transfers']['pairs']);
        $this->assertSame(DemandTransferService::VERSION, $run->stats['transfers']['version']);
        $pool = DB::table('demand_transfers')->where('tenant_id', $this->tenant->id)->where('from_sku', 'ZERO')->where('to_sku', 'DIET')->first();
        $this->assertNotNull($pool, 'measured across the peer group');
        $this->assertEqualsWithDelta(0.60, (float) $pool->share, 0.02);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function productsLite(): array
    {
        $out = [];
        foreach (DB::table('products')->where('tenant_id', $this->tenant->id)->get() as $p) {
            $out[$p->sku] = ['name' => $p->name, 'category' => $p->category, 'subcategory' => $p->subcategory,
                'brand' => $p->brand, 'pack_size' => $p->pack_size, 'price' => (float) $p->selling_price];
        }

        return $out;
    }

    private function productsLiteFrom(array $defs): array
    {
        return array_map(fn ($d) => ['category' => $d[0], 'subcategory' => $d[1], 'brand' => $d[2], 'pack_size' => $d[3], 'price' => $d[4], 'name' => ''], $defs);
    }
}
