<?php

namespace Tests\Feature;

use App\Filament\Pages\AssortmentPlanPage;
use App\Filament\Pages\AssortmentSetup;
use App\Filament\Pages\AssortmentStudio;
use App\Models\ApiKey;
use App\Models\AssortmentGap;
use App\Models\AssortmentPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Intelligence\Substitution\TransferEstimator;
use App\Services\Assortment\AssortmentEngine;
use App\Services\Assortment\AssortmentUploads;
use App\Services\Assortment\PlanService;
use App\Services\Assortment\RangeOptimizer;
use App\Services\Assortment\ShelfSpace;
use App\Services\Assortment\StudioService;
use App\Services\Assortment\ValidationGate;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Concerns\SeedsAssortmentEstate;
use Tests\TestCase;

/**
 * v1.5 Phase 4 — shelf space: the optimiser fits changes within the shelf's
 * metres (and cuts over-spaced facings to make room), space productivity is
 * margin per metre, the files load, and it runs end to end — plans, accept,
 * the Studio's facings and "make room", the export. Without shelf data
 * everything keeps counting products (the Phase 2 and 3 tests guard that).
 */
class AssortmentSpaceTest extends TestCase
{
    use SeedsAssortmentEstate;

    private User $admin;

    private array $products = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant(['apps' => [Tenant::APP_ROOT_CAUSE, Tenant::APP_ASSORTMENT], 'currency' => 'AED']);
        $this->admin = $this->actingAsTenantAdmin($this->tenant);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->tenant);
    }

    // ── The optimiser, on a shelf built by hand ───────────────────────────────

    private function product(string $sku, string $sub, float $margin = 0.4, ?string $brand = 'Farm'): void
    {
        $this->products[$sku] = ['name' => $sku, 'category' => 'Dairy', 'subcategory' => $sub, 'brand' => $brand, 'pack_size' => '500g',
            'price' => 10.0, 'cost' => 10.0 * (1 - $margin)];
    }

    /** @param array<string,array{0:float,1:int,2:float,3?:?string,4?:int}> $skus sku => [sales a year, facings, width cm, protected, fewest facings] */
    private function shelf(array $skus, float $metres, array $entry = [], string $basis = ShelfSpace::BASIS_FACINGS): array
    {
        $out = [];
        $used = 0.0;
        foreach ($skus as $sku => $v) {
            [$sales, $f, $w] = $v;
            $out[$sku] = ['sales' => $sales, 'margin_rate' => 0.4, 'stock' => 500.0, 'protected' => $v[3] ?? null, 'name' => $sku,
                'width' => $w / 100, 'facings' => $f, 'min_facings' => $v[4] ?? ($basis === ShelfSpace::BASIS_FACINGS ? 1 : $f), 'max_facings' => null];
            $used += $w / 100 * $f;
        }
        $s = array_sum(array_column($out, 'sales'));

        return ['skus' => $out, 'baseline' => ['sales' => $s, 'margin' => $s * 0.4, 'stock' => 500.0 * count($out), 'count' => count($out)],
            'space' => ['basis' => $basis, 'metres' => $metres, 'used' => $used, 'entry' => array_map(fn ($e) => ['width' => $e[0] / 100, 'facings' => $e[1]], $entry),
                'note' => null]];
    }

    private function add(string $sku, float $gross): array
    {
        return ['kind' => 'add', 'gap_id' => crc32($sku), 'sku' => $sku, 'name' => $sku, 'tier' => 'likely', 'confidence' => 0.6,
            'gross_sales' => $gross, 'current_sales' => 0.0, 'margin_rate' => 0.4, 'stock_value' => 0.0, 'units_per_day' => 1.0, 'cost' => 6.0];
    }

    private function delist(string $sku, float $sales): array
    {
        return ['kind' => 'delist', 'gap_id' => crc32($sku), 'sku' => $sku, 'name' => $sku, 'tier' => 'likely', 'confidence' => 0.6,
            'gross_sales' => 0.0, 'current_sales' => $sales, 'margin_rate' => 0.4, 'stock_value' => 300.0, 'units_per_day' => 0.0, 'cost' => 6.0];
    }

    private function optimise(array $shelf, array $candidates, array $strategy = []): array
    {
        $estimator = new TransferEstimator();

        return app(RangeOptimizer::class)->optimise($shelf, $candidates,
            $strategy + ['role' => 'routine', 'objective' => 'balanced', 'room' => 0.1, 'sales_floor' => null, 'max_size' => null],
            fn (string $sku, array $onShelf) => $estimator->estimate($sku, $onShelf, $this->products));
    }

    private function keys(array $plan): array
    {
        return array_values(array_map(fn ($c) => $c['key'], array_filter($plan['changes'], fn ($c) => $c['kind'] !== 'protect')));
    }

    private function milk(): void
    {
        foreach (['M1', 'M2', 'M3', 'SLOW', 'DUD'] as $m) {
            $this->product($m, 'Milk');
        }
        foreach (['YOG', 'WIDE', 'NARROW'] as $y) {
            $this->product($y, 'Yogurt');
        }
    }

    public function test_an_add_that_fits_the_free_metres_goes_in(): void
    {
        $this->milk();
        $plan = $this->optimise($this->shelf(['M1' => [20000, 2, 20], 'M2' => [20000, 2, 20], 'M3' => [20000, 2, 20]], 1.5, ['YOG' => [20, 1]]),
            [$this->add('YOG', 6000)]);

        $this->assertSame(['add:YOG'], $this->keys($plan));
        $this->assertEqualsWithDelta(0.2, $plan['changes'][0]['metres'], 1e-6);
        $this->assertSame('facings', $plan['constraints']['space']['basis']);
        $this->assertEqualsWithDelta(1.4, $plan['impact']['space']['used_after'], 1e-6);
        $this->assertNull($plan['constraints']['max_size'], 'the metres are the limit, not a count');
        $this->assertStringContainsString('Takes 0.20 m of shelf', $plan['changes'][0]['why']);
    }

    public function test_a_full_shelf_makes_room_by_cutting_an_over_spaced_facing(): void
    {
        $this->milk();
        $plan = $this->optimise($this->shelf(['M1' => [20000, 2, 20], 'M2' => [20000, 2, 20], 'SLOW' => [500, 3, 20]], 1.4, ['YOG' => [20, 1]]),
            [$this->add('YOG', 6000)]);

        $this->assertSame(['add:YOG', 'facings:SLOW'], $this->keys($plan));
        $cut = collect($plan['changes'])->firstWhere('key', 'facings:SLOW');
        $this->assertSame([3, 2], [$cut['from'], $cut['to']]);
        $this->assertEqualsWithDelta(-0.2, $cut['metres'], 1e-6);
        $this->assertStringContainsString('Over-spaced', $cut['why']);
        $this->assertEqualsWithDelta(1.4, $plan['impact']['space']['used_after'], 1e-6, 'fills the shelf exactly');
        $this->assertStringContainsString('one facing fewer on SLOW', $plan['changes'][0]['why']);
        $this->assertSame('likely', $plan['confidence_tier'], 'a facings row carries no confidence of its own');
    }

    public function test_with_nothing_over_spaced_an_add_needs_a_delist_or_waits(): void
    {
        $this->milk();
        $full = $this->shelf(['M1' => [20000, 2, 20], 'M2' => [20000, 2, 20], 'DUD' => [1000, 1, 20]], 1.0, ['YOG' => [20, 1]]);

        $waits = $this->optimise($full, [$this->add('YOG', 6000)]);
        $this->assertSame([], $this->keys($waits));
        $this->assertSame('shelf_full', $waits['left_out'][0]['reason']);
        $this->assertStringContainsString('No room on the shelf', $waits['left_out'][0]['why']);
        $this->assertContains('metres', $waits['constraints']['bound']);

        $swaps = $this->optimise($full, [$this->add('YOG', 6000), $this->delist('DUD', 1000)]);
        $this->assertContains('swap:DUD>YOG', $this->keys($swaps));
    }

    public function test_an_over_full_shelf_is_brought_within_its_metres_first(): void
    {
        $this->milk();
        // 1.6 m on a 1.4 m shelf: the over-spaced product loses a facing first.
        $cut = $this->optimise($this->shelf(['M1' => [20000, 2, 20], 'M2' => [20000, 2, 20], 'SLOW' => [500, 4, 20]], 1.4), []);
        $row = collect($cut['changes'])->firstWhere('key', 'facings:SLOW');
        $this->assertSame([4, 3], [$row['from'], $row['to']]);
        $this->assertStringStartsWith('Needed to bring the shelf within its metres', $row['why']);

        // Nothing to cut (its fewest is 4): the delist the evidence supports goes.
        $delist = $this->optimise($this->shelf(['M1' => [20000, 2, 20], 'M2' => [20000, 2, 20], 'SLOW' => [500, 4, 20, null, 4]], 1.4),
            [$this->delist('SLOW', 500)]);
        $this->assertSame(['delist:SLOW'], $this->keys($delist));

        // Cut first, then delisted with an add in its place: one row, and its reason and figure agree.
        $swap = $this->optimise($this->shelf(['M1' => [20000, 2, 20], 'M2' => [20000, 2, 20], 'SLOW' => [500, 4, 20]], 1.4, ['YOG' => [20, 2]]),
            [$this->add('YOG', 6000), $this->delist('SLOW', 500)]);
        $row = collect($swap['changes'])->first(fn ($c) => in_array($c['kind'], ['swap', 'delist'], true) && ($c['out_sku'] ?? $c['sku']) === 'SLOW');
        $this->assertNotNull($row);
        $this->assertNull(collect($swap['changes'])->firstWhere('key', 'facings:SLOW'), 'no facings row for a product that goes');
        $this->assertStringContainsString(($row['metres'] >= 0 ? 'Takes ' : 'Frees ') . number_format(abs($row['metres']), 2) . ' m', $row['why']);
        $this->assertLessThanOrEqual(1.4 + 1e-6, $swap['impact']['space']['used_after']);

        // Neither: it says so and changes nothing.
        $stuck = $this->optimise($this->shelf(['M1' => [20000, 2, 20], 'M2' => [20000, 2, 20], 'SLOW' => [500, 4, 20, null, 4]], 1.4), []);
        $this->assertFalse($stuck['feasible']);
        $this->assertStringContainsString('it uses 1.60 m of 1.40 m', $stuck['reason']);
    }

    public function test_products_that_must_stay_cannot_need_more_than_the_shelf(): void
    {
        $this->milk();
        $plan = $this->optimise($this->shelf(['M1' => [20000, 3, 20, 'Must-stock', 3], 'M2' => [20000, 3, 20, 'Must-stock', 3]], 1.0), []);

        $this->assertFalse($plan['feasible']);
        $this->assertStringContainsString('the products that must stay need at least 1.20 m and the shelf has 1.00 m', $plan['reason']);
        $this->assertSame([], $plan['changes']);
    }

    public function test_space_productivity_is_margin_per_metre(): void
    {
        $this->milk();
        // A slow shelf (2,000 a year per product on 0.4 m): both adds raise margin per metre, the narrow one more.
        $shelf = $this->shelf(['M1' => [2000, 2, 20], 'M2' => [2000, 2, 20]], 1.1, ['WIDE' => [30, 1], 'NARROW' => [10, 1]]);
        $candidates = [$this->add('WIDE', 6000), $this->add('NARROW', 5000)];

        $this->assertSame(['add:WIDE'], $this->keys($this->optimise($shelf, $candidates, ['objective' => 'sales'])));
        $this->assertSame(['add:NARROW'], $this->keys($this->optimise($shelf, $candidates, ['objective' => 'space'])),
            'more margin per metre from the narrow one');
    }

    public function test_with_metres_but_no_facings_nothing_is_cut(): void
    {
        $this->milk();
        $shelf = $this->shelf(['M1' => [20000, 2, 20], 'M2' => [20000, 2, 20], 'SLOW' => [500, 2, 20]], 1.2, ['YOG' => [20, 2]], ShelfSpace::BASIS_METRES);
        $plan = $this->optimise($shelf, [$this->add('YOG', 6000)]);

        $this->assertSame([], $this->keys($plan), 'facings unknown: no facing is cut, so a full shelf needs a delist');
        $this->assertSame('metres', $plan['constraints']['space']['basis']);
    }

    // ── Loading the files ─────────────────────────────────────────────────────

    private function csv(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'space') . '.csv';
        file_put_contents($path, $body);

        return $path;
    }

    public function test_the_shelf_files_load_and_report_what_they_skip(): void
    {
        $this->seedEstate();
        $up = app(AssortmentUploads::class);

        $r = $up->importShelfSpace($this->tenant->id, $this->csv("store,format,category,metres\nS1,,Dairy,2.4\n,Supermarket,Dairy,3\nS2,,Frozen,4\nS9,,Dairy,2\n,Hypermarket,Dairy,5\nS3,,Dairy,abc\n"));
        $this->assertSame(2, $r['rows']);
        $this->assertSame(1, $r['stores']);
        $this->assertSame(1, $r['formats']);
        $this->assertCount(4, $r['skipped']);
        $this->assertStringContainsString('category "Frozen" not found', implode(' ', $r['skipped']));

        $r = $up->importProductSpace($this->tenant->id, $this->csv("sku,store,width,facings,min facings\nBASE-1,,10,2,1\nBASE-1,S1,,3,\nNOPE,,10,1,\nBASE-2,,,,\nBASE-3,,10,2,3\n"));
        $this->assertSame(2, $r['rows']);
        $this->assertSame(1, $r['widths']);
        $this->assertCount(3, $r['skipped']);

        // A re-upload replaces the row; the store's own metres beat its format's.
        $up->importShelfSpace($this->tenant->id, $this->csv("store,category,metres\nS1,Dairy,2.6\n"));
        $space = app(ShelfSpace::class)->forStore($this->tenant->id, $this->stores[1]);
        $this->assertSame([2.6, 'store'], $space['metres']['Dairy']);
        $this->assertSame([3.0, 'format'], app(ShelfSpace::class)->forStore($this->tenant->id, $this->stores[2])['metres']['Dairy']);
        $this->assertSame(3, (int) $space['rows']['BASE-1']['store']->facings, 'the store row, beside the chain row');

        $this->assertSame(4, $up->clearSpace($this->tenant->id));
    }

    public function test_missing_widths_and_facings_are_assumed_and_said(): void
    {
        $this->product('A', 'Milk');
        $this->product('B', 'Milk');
        $this->product('C', 'Cream');
        $this->product('NEW', 'Milk');
        $row = fn ($w, $f) => (object) ['width_cm' => $w, 'facings' => $f, 'min_facings' => null, 'max_facings' => null];
        $shelf = ['skus' => ['A' => ['sales' => 1.0, 'name' => 'A'], 'B' => ['sales' => 1.0, 'name' => 'B'], 'C' => ['sales' => 1.0, 'name' => 'C']],
            'baseline' => ['sales' => 3.0, 'margin' => 1.2, 'stock' => 0.0, 'count' => 3]];
        $store = ['metres' => ['Dairy' => [2.0, 'store']], 'rows' => ['A' => ['chain' => $row(20, 2)], 'B' => ['chain' => $row(null, 4)], 'C' => ['chain' => $row(40, null)]]];

        $s = ShelfSpace::apply($shelf, 'Dairy', $store, ['NEW'], $this->products);
        $this->assertSame('facings', $s['space']['basis']);
        $this->assertEqualsWithDelta(0.2, $s['skus']['B']['width'], 1e-9, "B's width: its subcategory's average");
        $this->assertTrue($s['skus']['B']['width_assumed']);
        $this->assertSame(3, $s['skus']['C']['facings'], "C's facings: the shelf's median");
        $this->assertEqualsWithDelta(0.2 * 2 + 0.2 * 4 + 0.4 * 3, $s['space']['used'], 1e-9);
        $this->assertSame(['width' => 0.2, 'facings' => 3, 'width_assumed' => true], $s['space']['entry']['NEW']);
        $this->assertStringContainsString('assumed', $s['space']['note']);

        $none = ShelfSpace::apply($shelf, 'Dairy', ['metres' => ['Dairy' => [2.0, 'store']], 'rows' => []], [], $this->products);
        $this->assertSame('count', $none['space']['basis']);
        $this->assertStringContainsString('no product in this category has a width', $none['space']['note']);
    }

    // ── End to end ────────────────────────────────────────────────────────────

    /** Store 1's Dairy shelf full at 2.4 m, DUD and SHIELD must-stock with 3 facings each (over-spaced for what they sell). */
    private function spacedEstate(): void
    {
        $this->seedEstate();
        foreach (['DUD', 'SHIELD'] as $sku) {
            DB::table('assortment_must_stock')->insert(['tenant_id' => $this->tenant->id, 'sku' => $sku, 'reason' => 'Contract',
                'created_at' => now(), 'updated_at' => now()]);
        }
        $up = app(AssortmentUploads::class);
        $up->importShelfSpace($this->tenant->id, $this->csv("store,category,metres\nS1,Dairy,2.4\n"));
        $lines = "sku,width,facings\nADD-ME,10,2\nOUTAGE,10,2\nDUD,10,3\nSHIELD,10,3\n";
        for ($n = 1; $n <= 8; $n++) {
            $lines .= "BASE-{$n},10,2\n";
        }
        $up->importProductSpace($this->tenant->id, $this->csv($lines));
        app(AssortmentEngine::class)->run($this->tenant);
    }

    private function storePlan(): AssortmentPlan
    {
        return AssortmentPlan::where('tenant_id', $this->tenant->id)->where('store_id', $this->stores[1])->where('category', 'Dairy')->latest('id')->firstOrFail();
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

    public function test_a_run_fits_the_plan_in_the_shelf_and_accepting_keeps_it_fitting(): void
    {
        $this->spacedEstate();
        $plan = $this->storePlan();

        $this->assertSame('facings', $plan->constraints['space']['basis']);
        $this->assertEqualsWithDelta(2.4, $plan->constraints['space']['used_before'], 1e-6);
        $keys = collect($plan->actionable())->pluck('key')->all();
        $this->assertContains('add:ADD-ME', $keys);
        $this->assertContains('facings:DUD', $keys, 'room made on the over-spaced must-stock products');
        $this->assertContains('facings:SHIELD', $keys);
        $this->assertLessThanOrEqual(2.4 + 1e-6, $plan->impact['space']['used_after']);

        $this->goLive();
        $plan = $this->storePlan();
        try {
            app(PlanService::class)->accept($plan, $this->admin, null, null, null, ['facings:DUD']);
            $this->fail('leaving out a cut that makes room must not be accepted');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('of its 2.40 m', $e->getMessage());
        }
        app(PlanService::class)->accept($plan->fresh(), $this->admin, null, null, null, ['add:ADD-ME', 'facings:DUD', 'facings:SHIELD']);
        $this->assertSame(AssortmentPlan::STATUS_ACCEPTED, $plan->fresh()->status, 'leaving out the add and its cuts together fits');

        $this->get(AssortmentPlanPage::getUrl(['plan' => $plan->id], tenant: $this->tenant))->assertOk()
            ->assertSee('Shelf used')->assertSee('3 → 2 facings')->assertSee('Shelf metres and facings');

        [, $token] = ApiKey::generate($this->tenant->id, 'bi', [ApiKey::SCOPE_READ_EXPORTS]);
        $row = collect($this->getJson('/api/v1/exports/range_plans', ['X-Api-Key' => $token])->assertOk()->json('data'))->firstWhere('id', $plan->id);
        $this->assertSame('facings', $row['space_basis']);
        $this->assertEquals(2.4, (float) $row['shelf_metres']);
    }

    public function test_the_studio_fits_the_shelf_sets_facings_and_makes_room(): void
    {
        $this->spacedEstate();
        $svc = app(StudioService::class);
        $ctx = $svc->context($this->tenant, $this->stores[1], 'Dairy');
        $this->assertSame('facings', $ctx['shelf']['space']['basis']);

        $over = $svc->simulate($ctx, ['picks' => [['kind' => 'add', 'sku' => 'ADD-ME']]] + StudioService::blank());
        $this->assertSame(['metres'], array_column($over['violations'], 'key'));
        $this->assertNotNull($over['room_cuts']);

        $fixed = $svc->makeRoom($ctx, ['picks' => [['kind' => 'add', 'sku' => 'ADD-ME']]] + StudioService::blank());
        $this->assertSame(['DUD' => 2, 'SHIELD' => 2], $fixed['facings']);
        $this->assertSame([], $svc->simulate($ctx, $fixed)['violations']);

        $longer = $svc->simulate($ctx, ['metres' => 3.0, 'picks' => [['kind' => 'add', 'sku' => 'ADD-ME']]] + StudioService::blank());
        $this->assertSame([], $longer['violations'], 'a longer shelf fits it without cuts');

        $p = $svc->product($ctx, $over, 'DUD');
        $this->assertEqualsWithDelta(0.3, $p['space']['metres'], 1e-6);
        $this->assertNotNull($p['space']['per_metre']);

        $this->goLive();
        Livewire::test(AssortmentStudio::class, ['store' => $this->stores[1], 'category' => 'Dairy'])
            ->assertSee('Shelf used')->assertSee('Shelf metres and facings')
            ->call('facing', 'BASE-1', 1)->assertSet('facings', ['BASE-1' => 3])
            ->call('facing', 'BASE-1', -1)->assertSet('facings', [])
            ->call('facing', 'DUD', -1)->call('facing', 'DUD', -1)->call('facing', 'DUD', -1)->assertSet('facings', ['DUD' => 1])
            ->call('resetFacings', 'DUD')
            ->call('add', 'ADD-ME')->assertSee('Make room')
            ->call('makeRoom')->assertSet('facings', ['DUD' => 2, 'SHIELD' => 2])->assertDontSee('Breaks a limit')
            ->set('tab', 'compare')->assertSee('Shelf used');
    }

    public function test_without_shelf_data_nothing_changes(): void
    {
        $this->seedEstate();
        app(AssortmentEngine::class)->run($this->tenant);
        $plan = $this->storePlan();

        $this->assertSame('count', $plan->constraints['space']['basis']);
        $this->assertArrayNotHasKey('space', $plan->impact);
        $this->assertNotContains('facings', collect($plan->actionable())->pluck('kind')->all());
        $this->get(AssortmentSetup::getUrl(tenant: $this->tenant))->assertOk()->assertSee('Shelf space')->assertSee('Not loaded');
    }
}
