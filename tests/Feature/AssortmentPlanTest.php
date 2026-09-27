<?php

namespace Tests\Feature;

use App\Filament\Pages\AssortmentPlanPage;
use App\Filament\Pages\AssortmentPlans;
use App\Filament\Pages\AssortmentSetup;
use App\Filament\Pages\AssortmentValidation;
use App\Models\ApiKey;
use App\Models\AssortmentGap;
use App\Models\AssortmentPlan;
use App\Models\DecisionCase;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Intelligence\Substitution\TransferEstimator;
use App\Services\Assortment\AssortmentEngine;
use App\Services\Assortment\CategoryStrategy;
use App\Services\Assortment\OutcomeMeasurer;
use App\Services\Assortment\PlanService;
use App\Services\Assortment\RangeOptimizer;
use App\Services\Assortment\TenantAssortment;
use App\Services\Assortment\ValidationGate;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Concerns\SeedsAssortmentEstate;
use Tests\TestCase;

/**
 * v1.5 Phase 2 — range plans: the optimiser (competing adds and delists,
 * limits, swaps, feasibility, objectives, the sales floor) and the plan's life
 * (built after a run, reviewed, accepted whole with changes unticked as one
 * reset task, done, measured, learned from).
 */
class AssortmentPlanTest extends TestCase
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

    // ── The optimiser, on a shelf built by hand ───────────────────────────────

    private array $products = [];

    private function product(string $sku, string $sub, float $margin = 0.4, ?string $brand = 'Farm'): void
    {
        $this->products[$sku] = ['name' => $sku, 'category' => 'Dairy', 'subcategory' => $sub, 'brand' => $brand, 'pack_size' => '500g',
            'price' => 10.0, 'cost' => 10.0 * (1 - $margin)];
    }

    /** @param array<string,array{0:float,1:?string}> $skus sku => [sales a year, protected reason] */
    private function shelf(array $skus): array
    {
        $out = [];
        foreach ($skus as $sku => [$sales, $protected]) {
            $out[$sku] = ['sales' => $sales, 'margin_rate' => 0.4, 'stock' => 500.0, 'protected' => $protected, 'name' => $sku];
        }
        $s = array_sum(array_column($out, 'sales'));

        return ['skus' => $out, 'baseline' => ['sales' => $s, 'margin' => $s * 0.4, 'stock' => 500.0 * count($out), 'count' => count($out)]];
    }

    private function add(string $sku, float $gross, ?float $margin = 0.4, float $units = 1.0): array
    {
        return ['kind' => 'add', 'gap_id' => crc32($sku), 'sku' => $sku, 'name' => $sku, 'tier' => 'likely', 'confidence' => 0.6,
            'gross_sales' => $gross, 'current_sales' => 0.0, 'margin_rate' => $margin, 'stock_value' => 0.0, 'units_per_day' => $units, 'cost' => 6.0];
    }

    private function delist(string $sku, float $sales, float $stock = 1000.0): array
    {
        return ['kind' => 'delist', 'gap_id' => crc32($sku), 'sku' => $sku, 'name' => $sku, 'tier' => 'likely', 'confidence' => 0.6,
            'gross_sales' => 0.0, 'current_sales' => $sales, 'margin_rate' => 0.4, 'stock_value' => $stock, 'units_per_day' => 0.0, 'cost' => 6.0];
    }

    private function optimise(array $shelf, array $candidates, array $strategy): array
    {
        $estimator = new TransferEstimator();

        return app(RangeOptimizer::class)->optimise($shelf, $candidates,
            $strategy + ['role' => 'routine', 'objective' => 'sales', 'room' => 0.1, 'sales_floor' => null, 'max_size' => null],
            fn (string $sku, array $onShelf) => $estimator->estimate($sku, $onShelf, $this->products));
    }

    private function kinds(array $plan): array
    {
        return collect($plan['changes'])->reject(fn ($c) => $c['kind'] === 'protect')
            ->map(fn ($c) => $c['kind'] === 'swap' ? "swap:{$c['out_sku']}>{$c['sku']}" : "{$c['kind']}:{$c['sku']}")->values()->all();
    }

    public function test_competing_adds_one_in_then_the_other_is_worth_less_than_a_different_product(): void
    {
        foreach (['M1', 'M2', 'M3', 'M4'] as $m) {
            $this->product($m, 'Milk');
        }
        $this->product('YOG-A', 'Yogurt');
        $this->product('YOG-B', 'Yogurt');
        $this->product('KEFIR', 'Kefir', brand: 'Other');
        $shelf = $this->shelf(['M1' => [20000, null], 'M2' => [20000, null], 'M3' => [20000, null], 'M4' => [20000, null]]);

        $plan = $this->optimise($shelf, [$this->add('YOG-A', 6000), $this->add('YOG-B', 5800), $this->add('KEFIR', 4000)], ['max_size' => 6]);

        $this->assertSame(['add:YOG-A', 'add:KEFIR'], $this->kinds($plan), 'once YOG-A is in, YOG-B takes half its sales from it');
        $left = collect($plan['left_out'])->keyBy('sku');
        $this->assertSame('shelf_full', $left['YOG-B']['reason']);
        $this->assertSame(6, $plan['proposed_count']);
        $this->assertStringContainsString('Step 1', $plan['changes'][0]['why']);
    }

    public function test_competing_delists_the_second_loses_its_last_substitute_and_stays(): void
    {
        foreach (['M1', 'M2', 'M3', 'M4'] as $m) {
            $this->product($m, 'Milk');
        }
        $this->product('K1', 'Kefir');
        $this->product('K2', 'Kefir');
        $shelf = $this->shelf(['M1' => [40000, null], 'M2' => [38000, null], 'M3' => [36000, null], 'M4' => [34000, null],
            'K1' => [2000, null], 'K2' => [20000, null]]);

        $plan = $this->optimise($shelf, [$this->delist('K1', 2000), $this->delist('K2', 20000, 200.0)], ['objective' => 'balanced']);

        $this->assertSame(['delist:K1'], $this->kinds($plan));
        $left = collect($plan['left_out'])->keyBy('sku');
        $this->assertSame('no_value', $left['K2']['reason'], 'with K1 gone, K2 has no substitute left: too many buyers lost');
    }

    public function test_a_store_without_a_stock_file_still_gets_its_adds(): void
    {
        foreach (['M1', 'M2', 'M3'] as $m) {
            $this->product($m, 'Milk');
        }
        $this->product('YOG', 'Yogurt');
        $shelf = $this->shelf(['M1' => [20000, null], 'M2' => [20000, null], 'M3' => [20000, null]]);
        foreach ($shelf['skus'] as $sku => $p) {
            $shelf['skus'][$sku]['stock'] = 0.0;
        }
        $shelf['baseline']['stock'] = 0.0;

        $plan = $this->optimise($shelf, [$this->add('YOG', 6000)], ['objective' => 'balanced']);

        $this->assertSame(['add:YOG'], $this->kinds($plan), 'no stock baseline: stock does not count against the add');
        $this->assertNull($plan['impact']['stock_pct']);
    }

    public function test_holding_the_count_turns_an_add_into_a_swap_or_nothing(): void
    {
        foreach (['M1', 'M2', 'M3'] as $m) {
            $this->product($m, 'Milk');
        }
        $this->product('WEAK', 'Cream');
        $this->product('STAR', 'Yogurt');
        $shelf = $this->shelf(['M1' => [20000, null], 'M2' => [20000, null], 'M3' => [20000, null], 'WEAK' => [600, null]]);

        $noRoom = $this->optimise($shelf, [$this->add('STAR', 9000)], ['room' => 0.0]);
        $this->assertSame([], $this->kinds($noRoom), 'no room and nothing to delist: no add');
        $this->assertSame('shelf_full', $noRoom['left_out'][0]['reason']);
        $this->assertContains('max_size', $noRoom['constraints']['bound']);

        $swap = $this->optimise($shelf, [$this->add('STAR', 9000), $this->delist('WEAK', 600)], ['room' => 0.0]);
        $this->assertSame(['swap:WEAK>STAR'], $this->kinds($swap), 'the add is paid for by the delist');
        $this->assertSame(4, $swap['proposed_count']);
    }

    public function test_a_plan_that_cannot_meet_its_limits_says_so_and_changes_nothing(): void
    {
        foreach (['M1', 'M2', 'M3'] as $m) {
            $this->product($m, 'Milk');
        }
        $shelf = $this->shelf(['M1' => [1000, 'Must-stock'], 'M2' => [1000, 'Must-stock'], 'M3' => [1000, 'New product']]);

        $plan = $this->optimise($shelf, [], ['max_size' => 2]);
        $this->assertFalse($plan['feasible']);
        $this->assertStringContainsString('No feasible range', $plan['reason']);
        $this->assertStringContainsString('3 products must stay', $plan['reason']);
        $this->assertSame([], $plan['changes']);

        $open = $this->shelf(['M1' => [1000, null], 'M2' => [1000, null], 'M3' => [1000, null]]);
        $plan = $this->optimise($open, [], ['max_size' => 2]);
        $this->assertFalse($plan['feasible']);
        $this->assertStringContainsString('only 0 product(s) have the evidence to be delisted', $plan['reason']);
    }

    public function test_the_objective_changes_the_plan(): void
    {
        foreach (['M1', 'M2', 'M3'] as $m) {
            $this->product($m, 'Milk');
        }
        $this->product('VOLUME', 'Yogurt', margin: 0.1);
        $this->product('RICH', 'Cheese', margin: 0.5);
        $shelf = $this->shelf(['M1' => [20000, null], 'M2' => [20000, null], 'M3' => [20000, null]]);
        $candidates = [$this->add('VOLUME', 10000, 0.1), $this->add('RICH', 6000, 0.5)];

        $this->assertSame(['add:VOLUME'], $this->kinds($this->optimise($shelf, $candidates, ['objective' => 'sales', 'max_size' => 4])));
        $this->assertSame(['add:RICH'], $this->kinds($this->optimise($shelf, $candidates, ['objective' => 'margin', 'max_size' => 4])));
    }

    public function test_the_sales_floor_keeps_a_plan_from_buying_savings_with_sales(): void
    {
        foreach (['M1', 'M2'] as $m) {
            $this->product($m, 'Milk');
        }
        $this->product('BIG', 'Cream');
        $shelf = $this->shelf(['M1' => [20000, null], 'M2' => [20000, null], 'BIG' => [10000, null]]);

        $free = $this->optimise($shelf, [$this->delist('BIG', 10000, 30000)], ['objective' => 'working_capital']);
        $floored = $this->optimise($shelf, [$this->delist('BIG', 10000, 30000)], ['objective' => 'working_capital', 'sales_floor' => -0.01]);

        $this->assertSame(['delist:BIG'], $this->kinds($free));
        $this->assertSame([], $this->kinds($floored));
        $this->assertContains('sales_floor', $floored['constraints']['bound']);
        $this->assertSame('sales_floor', $floored['left_out'][0]['reason']);
    }

    public function test_the_role_sets_the_defaults_and_is_saved_per_category(): void
    {
        $this->assertSame(['role' => 'routine', 'objective' => 'balanced', 'room' => 0.1, 'sales_floor' => null, 'max_size' => null, 'set' => false],
            CategoryStrategy::for($this->tenant, 'Dairy'));

        CategoryStrategy::save($this->tenant, 'Dairy', ['role' => 'convenience', 'objective' => 'working_capital', 'room' => '0', 'sales_floor' => '']);
        $s = CategoryStrategy::for($this->tenant->fresh(), 'Dairy');
        $this->assertSame('convenience', $s['role']);
        $this->assertSame('working_capital', $s['objective']);
        $this->assertSame(0.0, $s['room']);
        $this->assertNull($s['sales_floor'], 'an explicit "no floor" is kept, not replaced by the working-capital default');
    }

    // ── The plan's life, through the run ─────────────────────────────────────

    private function goLiveDecisions(): void
    {
        $gate = app(ValidationGate::class);
        foreach (array_keys(AssortmentGap::TYPES) as $type) {
            foreach ($gate->sample($this->tenant, $type) as $g) {
                $gate->review($g, $this->admin, AssortmentGap::VERDICT_SENSIBLE);
            }
        }
        $this->assertTrue($gate->goLive($this->tenant->fresh(), $this->admin));
        $this->tenant = $this->tenant->fresh();
    }

    private function goLivePlans(): void
    {
        $svc = app(PlanService::class);
        foreach ($svc->sample($this->tenant) as $p) {
            $svc->review($p, $this->admin, AssortmentGap::VERDICT_SENSIBLE);
        }
        $this->assertTrue($svc->goLive($this->tenant->fresh(), $this->admin));
        $this->tenant = $this->tenant->fresh();
    }

    private function storePlan(): ?AssortmentPlan
    {
        return AssortmentPlan::where('tenant_id', $this->tenant->id)->where('store_id', $this->stores[1])->where('category', 'Dairy')
            ->whereNotIn('status', [AssortmentPlan::STATUS_REJECTED])->latest('id')->first();
    }

    public function test_a_run_builds_plans_in_review_and_they_open_after_their_own_review(): void
    {
        $this->seedEstate();
        $run = app(AssortmentEngine::class)->run($this->tenant);

        $this->assertGreaterThanOrEqual(1, $run->stats['plans']['plans']);
        $plan = $this->storePlan();
        $this->assertSame(AssortmentPlan::STATUS_DRAFT, $plan->status);
        $kinds = collect($plan->actionable())->pluck('kind')->all();
        $this->assertContains('add', $kinds);
        $this->assertContains('recover', $kinds, 'the stockout is fixed, never delisted');
        $this->assertSame(RangeOptimizer::VERSION, $plan->optimizer_version);

        // Plans cannot open before the decisions do.
        $this->assertFalse(app(PlanService::class)->goLive($this->tenant, $this->admin));
        $this->goLiveDecisions();
        $this->goLivePlans();
        $this->assertSame(AssortmentPlan::STATUS_PROPOSED, $plan->fresh()->status);

        // A rebuild keeps the verdict of an unchanged plan.
        $this->travel(1)->minutes();
        app(AssortmentEngine::class)->run($this->tenant);
        $this->assertSame(AssortmentGap::VERDICT_SENSIBLE, $this->storePlan()->review_verdict);
        $this->assertSame(AssortmentPlan::STATUS_PROPOSED, $this->storePlan()->status);
    }

    public function test_accept_whole_with_a_change_unticked_is_one_task_then_done_then_measured(): void
    {
        $this->seedEstate();
        app(AssortmentEngine::class)->run($this->tenant);
        $this->goLiveDecisions();
        $this->goLivePlans();
        $plan = $this->storePlan();
        $owner = $this->createUser($this->tenant);
        $delist = collect($plan->actionable())->firstWhere('kind', 'delist');
        $this->assertNotNull($delist, 'the estate plants a delist at store 1');

        app(PlanService::class)->accept($plan, $this->admin, $owner->id, now()->addDays(7)->toDateString(), 'Reset week 42', [$delist['key']]);
        $plan->refresh();

        $this->assertSame(AssortmentPlan::STATUS_ACCEPTED, $plan->status);
        $this->assertFalse(collect($plan->changes)->firstWhere('key', $delist['key'])['ticked']);
        $this->assertSame(AssortmentGap::STATUS_ACCEPTED, AssortmentGap::find(collect($plan->actionable())->firstWhere('kind', 'add')['gap_id'])->status);
        $this->assertSame(AssortmentGap::STATUS_OPEN, AssortmentGap::find($delist['gap_id'])->status, 'the unticked change is left open');
        $titles = DB::table('notifications')->where('notifiable_id', $owner->id)->pluck('data')->map(fn ($d) => json_decode($d, true)['title'] ?? '');
        $this->assertCount(1, $titles->filter(fn ($t) => str_starts_with($t, 'Range reset for you')), 'one reset task, one message');
        $this->assertCount(0, $titles->filter(fn ($t) => str_starts_with($t, 'Range task for you')));
        $this->assertSame(DecisionCase::DECISION_ADOPTED, DecisionCase::where('action_ref', 'assortment_plan:' . $plan->id)->value('decision'));

        // A shelf with a plan in progress is left alone by the next run.
        $this->travel(1)->minutes();
        app(AssortmentEngine::class)->run($this->tenant);
        $this->assertSame(1, AssortmentPlan::where('tenant_id', $this->tenant->id)->where('store_id', $this->stores[1])->where('category', 'Dairy')->count());

        $doneOn = Carbon::parse($this->asOf)->subDays(60);
        app(PlanService::class)->markDone($plan->fresh(), $this->admin, $doneOn->toDateString());
        $this->assertSame(AssortmentPlan::STATUS_IN_PROGRESS, $plan->fresh()->status);
        $this->assertSame(AssortmentGap::TASK_DONE, AssortmentGap::find(collect($plan->actionable())->firstWhere('kind', 'add')['gap_id'])->task_status);

        $rows = [];
        for ($d = 1; $d <= 56; $d++) {
            $rows[] = ['tenant_id' => $this->tenant->id, 'store_id' => $this->stores[1], 'sku' => 'ADD-ME', 'date' => $doneOn->copy()->addDays($d)->toDateString(),
                'units_sold' => 3, 'revenue' => 30, 'transaction_count' => 1, 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('sales_daily')->insert($rows);
        app(OutcomeMeasurer::class)->measureDue($this->tenant->id);

        $plan->refresh();
        $this->assertSame(AssortmentPlan::STATUS_MEASURED, $plan->status);
        $this->assertSame('category_sales', $plan->measurement['metric']);
        $this->assertEqualsWithDelta(56 * 30, $plan->measurement['uplift'], 60);
        $this->assertNotNull($plan->measurement['verdict']);
        $this->assertNotNull(DecisionCase::where('action_ref', 'assortment_plan:' . $plan->id)->value('resolved_at'));
    }

    public function test_a_rejected_plan_is_not_proposed_again(): void
    {
        $this->seedEstate();
        app(AssortmentEngine::class)->run($this->tenant);
        $this->goLiveDecisions();
        $this->goLivePlans();
        app(PlanService::class)->reject($this->storePlan(), $this->admin, 'The changes do not fit our category strategy');

        $this->travel(1)->minutes();
        app(AssortmentEngine::class)->run($this->tenant);

        $this->assertNull($this->storePlan(), 'the same change set is not proposed again');
        $this->assertSame(1, AssortmentPlan::where('tenant_id', $this->tenant->id)->where('status', AssortmentPlan::STATUS_REJECTED)->count());
    }

    public function test_holding_the_count_in_setup_changes_the_next_plan(): void
    {
        $this->seedEstate();
        CategoryStrategy::save($this->tenant, 'Dairy', ['role' => 'convenience', 'objective' => 'sales', 'room' => '0', 'sales_floor' => '']);
        app(AssortmentEngine::class)->run($this->tenant->fresh());

        $plan = $this->storePlan();
        $this->assertSame('convenience', $plan->role);
        $this->assertSame($plan->current_count, $plan->proposed_count, 'no room to grow: every add is paid for by a delist');
        $this->assertNotContains('add', collect($plan->actionable())->pluck('kind')->all());
    }

    public function test_the_screens_show_plans_to_the_right_people(): void
    {
        $this->seedEstate();
        app(AssortmentEngine::class)->run($this->tenant);
        $plan = $this->storePlan();

        $this->get(AssortmentPlans::getUrl(tenant: $this->tenant))->assertOk()->assertSee('Range plans are in review')->assertSee('Store 1 · Dairy');
        $this->get(AssortmentPlanPage::getUrl(['plan' => $plan->id], tenant: $this->tenant))->assertOk()
            ->assertSee('The changes, in the order they were chosen')->assertSee('Limits it respects')->assertSee('Estimated');
        $this->get(AssortmentValidation::getUrl(tenant: $this->tenant))->assertOk()->assertSee('Range plans');
        $this->get(AssortmentSetup::getUrl(tenant: $this->tenant))->assertOk()->assertSee('Category strategy')->assertSee('Dairy');

        // Someone who is not an admin sees nothing in review.
        $analyst = $this->createUser($this->tenant);
        $this->actingAs($analyst);
        $this->get(AssortmentPlans::getUrl(tenant: $this->tenant))->assertOk()->assertDontSee('Store 1 · Dairy');
        $this->get(AssortmentPlanPage::getUrl(['plan' => $plan->id], tenant: $this->tenant))->assertNotFound();
    }

    public function test_the_plan_page_unticks_and_accepts_through_livewire(): void
    {
        $this->seedEstate();
        app(AssortmentEngine::class)->run($this->tenant);
        $this->goLiveDecisions();
        $this->goLivePlans();
        $plan = $this->storePlan();
        $add = collect($plan->actionable())->firstWhere('kind', 'add');

        Livewire::test(AssortmentPlanPage::class, ['plan' => $plan->id])
            ->call('toggle', $add['key'])
            ->assertSet('unticked', [$add['key']])
            ->callAction('accept', ['assignee_id' => null, 'due_at' => now()->addDays(10)->toDateString(), 'note' => null])
            ->assertHasNoActionErrors();

        $this->assertSame(AssortmentPlan::STATUS_ACCEPTED, $plan->fresh()->status);
        $this->assertSame(AssortmentGap::STATUS_OPEN, AssortmentGap::find($add['gap_id'])->status);

        Livewire::test(AssortmentSetup::class)
            ->callAction('strategy', ['role' => 'destination', 'objective' => 'sales', 'room' => '0.15', 'sales_floor' => '', 'max_size' => null], ['category' => 'Dairy'])
            ->assertHasNoActionErrors();
        $this->assertSame('destination', CategoryStrategy::for($this->tenant->fresh(), 'Dairy')['role']);
    }

    public function test_plans_are_exported_once_open_never_in_review(): void
    {
        $this->seedEstate();
        app(AssortmentEngine::class)->run($this->tenant);
        [, $token] = ApiKey::generate($this->tenant->id, 'bi', [ApiKey::SCOPE_READ_EXPORTS]);
        $h = ['X-Api-Key' => $token];

        $this->assertSame([], $this->getJson('/api/v1/exports/range_plans', $h)->assertOk()->json('data'));
        $this->goLiveDecisions();
        $this->goLivePlans();
        $row = collect($this->getJson('/api/v1/exports/range_plans', $h)->assertOk()->json('data'))->firstWhere('store_id', $this->stores[1]);
        $this->assertSame('proposed', $row['status']);
        $this->assertNotNull($row['sales_mid']);
    }
}
