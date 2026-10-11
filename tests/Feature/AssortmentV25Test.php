<?php

namespace Tests\Feature;

use App\Filament\Dashboards\AssortmentDashboard;
use App\Filament\Pages\AssortmentBenchmarks;
use App\Filament\Pages\AssortmentGroups;
use App\Filament\Pages\AssortmentLearning;
use App\Filament\Pages\AssortmentPlanPage;
use App\Filament\Pages\AssortmentStudio;
use App\Models\AssortmentGap;
use App\Models\AssortmentPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Assortment\AssortmentEngine;
use App\Services\Assortment\CalibrationService;
use App\Services\Assortment\GroupVisuals;
use App\Services\Assortment\PlanService;
use App\Services\Assortment\StoreGroupings;
use App\Services\Assortment\StudioService;
use App\Services\Assortment\ValidationGate;
use App\Support\Apps\AppRegistry;
use App\Support\Viz;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Concerns\SeedsAssortmentEstate;
use Tests\TestCase;

/**
 * v2.5 — the visual layer: strategy cards in the Decision Studio, the charts on
 * the Assortment screens (server-drawn SVG, no inline CSS), and the left menu
 * that folds to icons.
 */
class AssortmentV25Test extends TestCase
{
    use SeedsAssortmentEstate;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant(['apps' => [Tenant::APP_ROOT_CAUSE, Tenant::APP_ASSORTMENT], 'currency' => 'AED']);
        $this->admin = $this->actingAsTenantAdmin($this->tenant);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->tenant);
    }

    private function goLive(): void
    {
        $gate = app(ValidationGate::class);
        foreach (array_keys(AssortmentGap::TYPES) as $type) {
            foreach ($gate->sample($this->tenant, $type) as $g) {
                $gate->review($g, $this->admin, AssortmentGap::VERDICT_SENSIBLE);
            }
        }
        $this->assertTrue($gate->goLive($this->tenant->fresh(), $this->admin));
        $plans = app(PlanService::class);
        foreach ($plans->sample($this->tenant->fresh()) as $p) {
            $plans->review($p, $this->admin, AssortmentGap::VERDICT_SENSIBLE);
        }
        $this->assertTrue($plans->goLive($this->tenant->fresh(), $this->admin));
        $this->tenant = $this->tenant->fresh();
    }

    private function live(): AssortmentPlan
    {
        $this->seedEstate();
        app(AssortmentEngine::class)->run($this->tenant);
        $this->goLive();

        return AssortmentPlan::where('tenant_id', $this->tenant->id)->where('store_id', $this->stores[1])->where('category', 'Dairy')->latest('id')->firstOrFail();
    }

    // ── The arithmetic ────────────────────────────────────────────────────────

    public function test_axis_ticks_are_round_and_cover_the_range(): void
    {
        $this->assertSame([0.0, 2.0, 4.0, 6.0, 8.0, 10.0], Viz::ticks(0, 10, 5));
        $t = Viz::ticks(-1800, 9800, 5);
        $this->assertLessThanOrEqual(-1800, $t[0]);
        $this->assertGreaterThanOrEqual(9800, end($t));
        $this->assertEqualsWithDelta(2500.0, $t[1] - $t[0], 1e-9);
        $this->assertCount(3, Viz::ticks(5, 5, 2), 'one value still gets an axis around it');
        $this->assertSame(100.0, Viz::scale(5, 0, 10, 0, 200) / 1);
    }

    public function test_heat_shades_read_both_ways_from_the_chain(): void
    {
        $this->assertSame('ax-viz-heat-up', Viz::heat(1.4)['class']);
        $this->assertSame('ax-viz-heat-down', Viz::heat(0.7)['class']);
        $this->assertGreaterThan(Viz::heat(1.1)['opacity'], Viz::heat(1.5)['opacity']);
        $this->assertSame('ax-viz-heat-bad', Viz::heat(0.1, 'good')['class']);
        $this->assertSame('ax-viz-heat-good', Viz::heat(0.95, 'good')['class']);
        $this->assertSame('ax-viz-cell--empty', Viz::heat(null)['class']);
        $this->assertStringStartsWith('M', Viz::arc(100, 100, 90, 60, 0, M_PI));
    }

    public function test_the_charts_are_svg_with_no_inline_css(): void
    {
        $html = Blade::render(<<<'B'
            <x-ui.viz.heatmap :rows="[['key' => 'g', 'label' => 'Group']]" :cols="[['key' => 'c', 'label' => 'Dairy']]" :cells="['g' => ['c' => ['v' => 1.3, 'text' => '130', 'tip' => 'Group · Dairy: 130']]]" :legend="['low' => 'a']" />
            <x-ui.viz.scatter :points="[['x' => 1, 'y' => 2, 's' => 1, 'tip' => 'Store A'], ['x' => 2, 'y' => 1, 's' => 2, 'tip' => 'Store B']]" :series="[1 => 'One', 2 => 'Two']" :diagonal="true" />
            <x-ui.viz.donut :segments="[['label' => 'One', 'value' => 3, 's' => 1], ['label' => 'Two', 'value' => 1, 's' => 2]]" center="4" />
            <x-ui.viz.bars :items="[['label' => 'Milk', 'value' => 0.9, 'text' => '9 of 10']]" :max="1.0" :markers="[['at' => 0.5, 'label' => 'expected']]" />
            <x-ui.viz.waterfall :steps="[['label' => 'Delists', 'value' => -100], ['label' => 'Adds', 'value' => 300]]" :total="['label' => 'Net change', 'value' => 200, 'range' => [50, 400]]" />
            <x-ui.viz.flow :source="['label' => 'Kefir', 'text' => 'its buyers']" :targets="[['label' => 'Milk', 'share' => 0.6], ['label' => 'Lost', 'share' => 0.4, 'kind' => 'lose']]" />
            <x-ui.viz.range :low="-0.01" :mid="0.02" :high="0.04" :min="-0.02" :max="0.05" tip="range" />
            B);
        $this->assertSame(7, preg_match_all('/<svg class="(ax-viz ax-viz--|ax-viz-donut|ax-range-svg)/', $html), 'one drawing each');
        $this->assertStringContainsString('<title>Group · Dairy: 130</title>', $html);
        $this->assertStringContainsString('<title>Store A</title>', $html);
        $this->assertStringContainsString('as estimated', $html);
        $this->assertStringContainsString('Net change', $html);
        $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/', $html, 'every look is a class; every size an SVG attribute');
    }

    // ── The menu folds to icons ───────────────────────────────────────────────

    public function test_the_menu_folds_to_one_icon_per_group(): void
    {
        $panel = Filament::getPanel('admin');
        $this->assertTrue($panel->isSidebarCollapsibleOnDesktop());
        foreach ($panel->getNavigationGroups() as $g) {
            $this->assertNotEmpty($g->getIcon(), $g->getLabel() . ' has an icon for the folded menu');
        }
        $this->assertSame(count(AppRegistry::allGroups()), count($panel->getNavigationGroups()));
        $this->assertStringContainsString("localStorage.getItem('isOpen')", AppRegistry::sidebarScript(Tenant::APP_ASSORTMENT));

        $this->seedEstate();
        $this->get(AssortmentGroups::getUrl(tenant: $this->tenant))->assertOk()
            ->assertSee('fi-sidebar-group-dropdown-trigger-btn', false)
            ->assertSee('Collapse sidebar', false);
    }

    // ── Strategy cards ────────────────────────────────────────────────────────

    public function test_the_studio_shows_strategies_side_by_side_with_one_recommended(): void
    {
        $this->live();
        $svc = app(StudioService::class);
        $ctx = $svc->context($this->tenant, $this->stores[1], 'Dairy');
        $s = $svc->strategies($ctx);

        $this->assertCount(4, $s['cards']);
        $this->assertCount(1, array_filter($s['cards'], fn ($c) => $c['recommended']));
        $this->assertTrue($s['cards'][0]['recommended'], 'the rules\' choice comes first');
        $this->assertSame($ctx['strategy']['objective'], StudioService::PRESETS[$s['cards'][0]['key']]['objective'] ?? $ctx['strategy']['objective']);
        $this->assertLessThanOrEqual(0.0, $s['scale']['min']);
        $this->assertGreaterThanOrEqual(0.0, $s['scale']['max']);
        foreach ($s['cards'] as $c) {
            $this->assertContains($c['risk']['level'], ['none', 'low', 'medium', 'high']);
            $this->assertGreaterThanOrEqual(0.0, $c['kept']);
            $this->assertLessThanOrEqual(1.0, $c['kept']);
            $this->assertSame($svc->preset($ctx, $c['key'])['summary'], $c['summary'], 'a card is the optimiser\'s own result, not a figure of its own');
            if ($c['same_as'] === null) {
                $this->assertLessThanOrEqual(4, count($c['actions']));
            }
        }

        Livewire::test(AssortmentStudio::class, ['store' => $this->stores[1], 'category' => 'Dairy'])
            ->assertSee('Strategies for this shelf')->assertSee('Recommended')->assertSee('Key actions')
            ->call('loadPreset', $s['cards'][1]['key'])
            ->assertSet('loaded', StudioService::PRESETS[$s['cards'][1]['key']]['label']);
    }

    public function test_risk_comes_from_confidence_and_the_low_end_of_the_range(): void
    {
        $base = ['changes' => 2, 'recovers' => 0, 'sales_pct' => [0.01, 0.02, 0.03], 'confidence_tier' => AssortmentGap::TIER_ESTABLISHED];
        $this->assertSame('low', StudioService::risk($base)['level']);
        $this->assertSame('medium', StudioService::risk(['confidence_tier' => AssortmentGap::TIER_LIKELY] + $base)['level']);
        $this->assertSame('medium', StudioService::risk(['sales_pct' => [-0.02, 0.01, 0.03]] + $base)['level']);
        $this->assertSame('high', StudioService::risk(['sales_pct' => [-0.05, 0.01, 0.03]] + $base)['level']);
        $this->assertSame('high', StudioService::risk(['confidence_tier' => AssortmentGap::TIER_SPECULATIVE] + $base)['level']);
        $this->assertSame('none', StudioService::risk(['changes' => 0] + $base)['level']);
    }

    public function test_risk_is_weighted_by_the_money_each_change_carries(): void
    {
        $sum = ['changes' => 2, 'recovers' => 1, 'sales_pct' => [0.01, 0.02, 0.03], 'confidence_tier' => AssortmentGap::TIER_SPECULATIVE];
        $changes = [
            ['kind' => AssortmentPlan::ADD, 'tier' => AssortmentGap::TIER_ESTABLISHED, 'sales' => [800, 990, 1200]],
            ['kind' => AssortmentPlan::RECOVER, 'tier' => AssortmentGap::TIER_SPECULATIVE, 'sales' => [5, 10, 20]],
        ];
        $this->assertSame('low', StudioService::risk($sum, $changes)['level'], 'one small unsure stock fix does not make the strategy risky');
        $changes[1]['sales'] = [500, 1500, 3000];
        $this->assertSame('high', StudioService::risk($sum, $changes)['level'], 'most of the money on speculative evidence');
        $changes[1]['ticked'] = false;
        $this->assertSame('low', StudioService::risk($sum, $changes)['level'], 'a change left out does not count');
    }

    public function test_store_groups_never_share_a_label(): void
    {
        $t = $this->tenant->id;
        foreach (['D1' => 'Dairy', 'B1' => 'Bakery', 'F1' => 'Frozen'] as $sku => $cat) {
            DB::table('products')->insert(['tenant_id' => $t, 'sku' => $sku, 'name' => $cat . ' item', 'category' => $cat, 'unit_cost' => 6, 'selling_price' => 10,
                'created_at' => now(), 'updated_at' => now()]);
        }
        $rows = [];
        // Twenty stores that sell almost alike: no group leans 15% to any category, so all would be "Balanced range".
        for ($i = 1; $i <= 20; $i++) {
            $store = DB::table('stores')->insertGetId(['tenant_id' => $t, 'name' => "Store {$i}", 'code' => "S{$i}", 'format' => 'Supermarket', 'created_at' => now(), 'updated_at' => now()]);
            $mix = [['D1' => 11, 'B1' => 10, 'F1' => 10], ['D1' => 10, 'B1' => 11, 'F1' => 10], ['D1' => 10, 'B1' => 10, 'F1' => 11], ['D1' => 10, 'B1' => 10, 'F1' => 10]][$i % 4];
            foreach ($mix as $sku => $u) {
                for ($day = 0; $day < 20; $day++) {
                    $rows[] = ['tenant_id' => $t, 'store_id' => $store, 'sku' => $sku, 'date' => Carbon::parse($this->asOf)->subDays($day)->toDateString(),
                        'units_sold' => $u + ($i % 3), 'revenue' => ($u + ($i % 3)) * 10, 'transaction_count' => 1, 'created_at' => now(), 'updated_at' => now()];
                }
            }
        }
        DB::table('sales_daily')->insert($rows);
        $groups = app(\App\Platform\Intelligence\Clustering\Strategies\RangeMixClustering::class)->clusterWith($t, [], $this->asOf);
        $this->assertGreaterThan(1, count($groups));
        $labels = array_column($groups, 'label');
        $this->assertSame(count($labels), count(array_unique($labels)), 'every group reads apart: ' . implode(' / ', $labels));
        $this->assertGreaterThan(1, count(array_filter($labels, fn ($l) => str_starts_with($l, 'Balanced range'))), 'the case this guards: several balanced groups');
    }

    // ── Charts on the screens ─────────────────────────────────────────────────

    public function test_the_plan_page_draws_where_the_change_comes_from(): void
    {
        $plan = $this->live();
        $w = app(PlanService::class)->waterfall($plan);
        $this->assertNotNull($w);
        $acting = collect($plan->actionable())->filter(fn ($c) => ($c['ticked'] ?? true) && $c['kind'] !== AssortmentPlan::FACINGS);
        $this->assertEqualsWithDelta($acting->sum(fn ($c) => (float) $c['sales'][1]), $w['total']['value'], 0.05);
        $this->assertEqualsWithDelta(array_sum(array_column($w['steps'], 'value')), $w['total']['value'], 0.05);
        $this->assertLessThanOrEqual($w['total']['value'], $w['total']['low']);
        $this->assertGreaterThanOrEqual($w['total']['value'], $w['total']['high']);

        // Unticking a change takes it out.
        $first = $acting->first();
        $less = app(PlanService::class)->waterfall($plan, [$first['key']]);
        $this->assertEqualsWithDelta($w['total']['value'] - (float) $first['sales'][1], $less['total']['value'] ?? 0.0, 0.05);

        $this->get(AssortmentPlanPage::getUrl(['plan' => $plan->id], tenant: $this->tenant))->assertOk()
            ->assertSee('Where the change in category sales comes from')->assertSee('Net change');
    }

    public function test_store_groups_are_drawn_from_what_the_stores_sell(): void
    {
        $t = $this->tenant->id;
        foreach (['D1' => 'Dairy', 'B1' => 'Bakery'] as $sku => $cat) {
            DB::table('products')->insert(['tenant_id' => $t, 'sku' => $sku, 'name' => $cat . ' item', 'category' => $cat, 'unit_cost' => 6, 'selling_price' => 10,
                'created_at' => now(), 'updated_at' => now()]);
        }
        $rows = [];
        for ($i = 1; $i <= 10; $i++) {
            $this->stores[$i] = DB::table('stores')->insertGetId(['tenant_id' => $t, 'name' => "Store {$i}", 'code' => "S{$i}", 'format' => 'Supermarket',
                'region' => 'North', 'latitude' => 25 + $i / 100, 'longitude' => 55 + $i / 100, 'created_at' => now(), 'updated_at' => now()]);
            [$d, $b] = $i <= 5 ? [8, 2] : [2, 8];
            for ($day = 0; $day < 30; $day++) {
                foreach (['D1' => $d, 'B1' => $b] as $sku => $u) {
                    $rows[] = ['tenant_id' => $t, 'store_id' => $this->stores[$i], 'sku' => $sku, 'date' => Carbon::parse($this->asOf)->subDays($day)->toDateString(),
                        'units_sold' => $u, 'revenue' => $u * 10, 'transaction_count' => 1, 'created_at' => now(), 'updated_at' => now()];
                }
            }
        }
        DB::table('sales_daily')->insert($rows);
        $g = app(StoreGroupings::class)->propose($this->tenant, $this->admin);

        $v = app(GroupVisuals::class)->build($t, $g);
        $this->assertSame(10, $v['stores']);
        $this->assertSame([5, 5], array_column($v['donut'], 'value'));
        $this->assertCount(10, $v['scatter']);
        $this->assertCount(10, $v['map']);
        $dairyLed = collect($g->groups)->firstWhere('label', 'Dairy-led');
        $cell = $v['heat']['cells'][$dairyLed['key']]['Dairy'];
        $this->assertGreaterThan(1.0, $cell['v'], 'the Dairy-led group sells more dairy than the chain');
        $this->assertEqualsWithDelta(0.3, collect($v['scatter'])->max('y'), 0.001, 'an 80/20 store against a 50/50 chain has 30% of its sales in a different mix');
        $this->assertEqualsWithDelta(1.0, collect($v['scatter'])->avg('x'), 0.001, 'every store prices like the chain');

        $this->get(AssortmentGroups::getUrl(tenant: $this->tenant))->assertOk()
            ->assertSee('Stores per group')->assertSee('What each group sells against the chain')->assertSee('Where the stores are');
    }

    public function test_learning_plots_every_measured_result_against_its_estimate(): void
    {
        $this->seedEstate();
        $t = $this->tenant->id;
        $make = fn (string $type, float $exp, float $act, string $strength) => AssortmentGap::create([
            'tenant_id' => $t, 'store_id' => $this->stores[1], 'sku' => 'ADD-ME', 'peer_group' => 'format:supermarket', 'type' => $type, 'status' => AssortmentGap::STATUS_ACCEPTED,
            'confidence_tier' => AssortmentGap::TIER_LIKELY, 'measured_at' => now(), 'as_of_date' => $this->asOf,
            'evidence' => ['expected_sales_change_per_year' => $exp], 'measurement' => ['uplift_per_year' => $act, 'expected_per_year' => $exp, 'strength' => $strength, 'verdict' => 'success'],
        ]);
        $make(AssortmentGap::TYPE_ADD, 1000, 800, 'strong');
        $make(AssortmentGap::TYPE_DELIST, -200, -150, 'weak');

        $p = app(CalibrationService::class)->points($t);
        $this->assertSame(2, $p['n']);
        $add = collect($p['points'])->firstWhere('s', 1);
        $this->assertSame([1000.0, 800.0, false], [$add['x'], $add['y'], $add['hollow']]);
        $this->assertTrue(collect($p['points'])->firstWhere('s', 2)['hollow'], 'a weak measurement is drawn hollow');

        $this->get(AssortmentLearning::getUrl(tenant: $this->tenant))->assertOk()->assertSee('Measured against estimated')->assertSee('as estimated');
    }

    public function test_benchmarks_and_the_dashboard_draw_coverage(): void
    {
        $this->live();
        $this->get(AssortmentBenchmarks::getUrl(tenant: $this->tenant))->assertOk()
            ->assertSee('How widely the top products are carried')->assertSee('Who carries what');

        $map = AssortmentDashboard::healthMap([
            ['group' => 'North', 'category' => 'Dairy', 'coverage' => 0.9, 'tail' => 0.1, 'stockout' => 0.05],
            ['group' => 'South', 'category' => 'Dairy', 'coverage' => 0.6, 'tail' => 0.2, 'stockout' => 0.1],
            ['group' => 'North', 'category' => 'Bakery', 'coverage' => 0.3, 'tail' => 0.3, 'stockout' => 0.2],
        ]);
        $this->assertSame(['Bakery', 'Dairy'], array_column($map['rows'], 'key'), 'weakest category first');
        $this->assertSame(['North', 'South'], array_column($map['cols'], 'key'));
        $this->assertSame('90%', $map['cells']['Dairy']['North']['text']);
        $this->assertNull(AssortmentDashboard::healthMap([]));

        $html = view('filament.dashboards.assortment', (new AssortmentDashboard)->data($this->tenant))->render();
        $this->assertStringContainsString('Range health by category and peer group', $html);
    }
}
