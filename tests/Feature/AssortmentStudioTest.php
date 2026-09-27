<?php

namespace Tests\Feature;

use App\Filament\Pages\AssortmentPlanPage;
use App\Filament\Pages\AssortmentStudio;
use App\Models\AssortmentGap;
use App\Models\AssortmentPlan;
use App\Models\AssortmentScenario;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Assortment\AssortmentEngine;
use App\Services\Assortment\PlanService;
use App\Services\Assortment\StudioService;
use App\Services\Assortment\ValidationGate;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Concerns\SeedsAssortmentEstate;
use Tests\TestCase;

/**
 * v1.5 Phase 3 — the Assortment Decision Studio: a person's changes valued in
 * order on the shelf as it stands, limits reported, protections, presets from
 * the optimiser, products similar stores carry, the product panel, saved
 * scenarios compared as saved, and a tried range made the shelf's plan —
 * accepted as one reset task and left alone by the nightly rebuild.
 *
 * Store 1's Dairy shelf (from the estate): OUTAGE (keeps running out), DUD and
 * SHIELD (weak: delists), BASE-1..8; ADD-ME is the add; RARE is planted here —
 * carried by two similar stores only, so the engine does not propose it.
 */
class AssortmentStudioTest extends TestCase
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

    private function estate(): void
    {
        $this->seedEstate();
        DB::table('products')->insert(['tenant_id' => $this->tenant->id, 'sku' => 'RARE', 'name' => 'Sheep yogurt', 'category' => 'Dairy',
            'subcategory' => 'Chilled', 'unit_cost' => 6, 'selling_price' => 10, 'created_at' => now(), 'updated_at' => now()]);
        $sales = $stock = [];
        $end = Carbon::parse($this->asOf);
        for ($d = 0; $d < 200; $d++) {
            foreach ([2, 3] as $n) {
                $date = $end->copy()->subDays($d)->toDateString();
                $stock[] = ['tenant_id' => $this->tenant->id, 'store_id' => $this->stores[$n], 'sku' => 'RARE', 'on_hand_qty' => 20,
                    'as_of_date' => $date, 'created_at' => now(), 'updated_at' => now()];
                $sales[] = ['tenant_id' => $this->tenant->id, 'store_id' => $this->stores[$n], 'sku' => 'RARE', 'date' => $date,
                    'units_sold' => 3, 'revenue' => 30, 'transaction_count' => 1, 'created_at' => now(), 'updated_at' => now()];
            }
        }
        foreach (array_chunk($sales, 1000) as $c) {
            DB::table('sales_daily')->insert($c);
        }
        foreach (array_chunk($stock, 1000) as $c) {
            DB::table('inventory_levels')->insert($c);
        }
        app(AssortmentEngine::class)->run($this->tenant);
    }

    private function ctx(): array
    {
        return app(StudioService::class)->context($this->tenant->fresh(), $this->stores[1], 'Dairy');
    }

    private function sim(array $inputs): array
    {
        return app(StudioService::class)->simulate($this->ctx(), $inputs + StudioService::blank());
    }

    private function row(array $result, string $key): ?array
    {
        return collect($result['changes'])->firstWhere('key', $key);
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

    public function test_changes_are_valued_in_order_on_the_shelf_as_it_stands(): void
    {
        $this->estate();
        $ctx = $this->ctx();
        $this->assertSame(11, $ctx['shelf']['baseline']['count']);
        $this->assertSame(['ADD-ME' => 'add', 'DUD' => 'delist', 'OUTAGE' => 'recover', 'SHIELD' => 'delist'],
            collect($ctx['engine'])->map(fn ($c) => $c['kind'])->sortKeys()->all());

        $today = $this->sim([]);
        $this->assertSame(11, $today['proposed_count']);
        $this->assertNotNull($this->row($today, 'recover:OUTAGE'), 'the stock fix is in from the start');
        $this->assertSame([], $today['violations']);

        // On a short shelf (six ordinary products taken off first) each substitute counts.
        $short = array_map(fn ($n) => ['kind' => 'delist', 'sku' => "BASE-{$n}"], range(1, 6));
        $alone = $this->row($this->sim(['picks' => [...$short, ['kind' => 'delist', 'sku' => 'SHIELD']]]), 'delist:SHIELD');
        $second = $this->sim(['picks' => [...$short, ['kind' => 'delist', 'sku' => 'DUD'], ['kind' => 'delist', 'sku' => 'SHIELD']]]);
        $this->assertLessThan($alone['sales'][1], $this->row($second, 'delist:SHIELD')['sales'][1],
            'with DUD already gone, SHIELD\'s buyers have one substitute fewer: more of its sales are lost');
        $this->assertSame(3, $second['proposed_count']);
        $this->assertStringStartsWith('Change 8', $this->row($second, 'delist:SHIELD')['why']);
        $this->assertSame('engine', $this->row($second, 'delist:DUD')['source']);

        // A product the engine did not flag can be taken off too: the person's change, at low confidence.
        $mine = $this->row($this->sim(['picks' => [['kind' => 'delist', 'sku' => 'BASE-3']]]), 'delist:BASE-3');
        $this->assertSame('user', $mine['source']);
        $this->assertSame('speculative', $mine['tier']);
        $this->assertLessThan(0, $mine['sales'][1]);
    }

    public function test_limits_are_reported_not_enforced_and_block_the_plan(): void
    {
        $this->estate();
        $over = $this->sim(['max_size' => 10]);
        $this->assertSame(['max_size'], array_column($over['violations'], 'key'));

        $under = $this->sim(['min_size' => 11, 'picks' => [['kind' => 'delist', 'sku' => 'BASE-1']]]);
        $this->assertSame(['min_size'], array_column($under['violations'], 'key'));

        $this->expectException(HttpException::class);
        app(StudioService::class)->makePlan($this->tenant, $this->admin, $this->ctx(), ['max_size' => 10, 'picks' => [['kind' => 'add', 'sku' => 'ADD-ME']]] + StudioService::blank());
    }

    public function test_protected_products_stay_unless_unprotected(): void
    {
        $this->estate();
        DB::table('assortment_must_stock')->insert(['tenant_id' => $this->tenant->id, 'sku' => 'SHIELD', 'reason' => 'Private label',
            'created_at' => now(), 'updated_at' => now()]);

        $kept = $this->sim(['picks' => [['kind' => 'delist', 'sku' => 'SHIELD'], ['kind' => 'delist', 'sku' => 'OUTAGE']]]);
        $this->assertSame(11, $kept['proposed_count']);
        $this->assertSame(['SHIELD', 'OUTAGE'], array_column($kept['skipped'], 'sku'));
        $this->assertStringContainsString('Private label', $kept['skipped'][0]['why']);
        $this->assertStringContainsString('running out', $kept['skipped'][1]['why']);

        $freed = $this->sim(['unprotect' => ['SHIELD'], 'picks' => [['kind' => 'delist', 'sku' => 'SHIELD']]]);
        $this->assertSame(10, $freed['proposed_count']);

        $held = $this->sim(['protect' => ['BASE-2'], 'picks' => [['kind' => 'delist', 'sku' => 'BASE-2']]]);
        $this->assertSame(11, $held['proposed_count']);
    }

    public function test_presets_come_from_the_optimiser_and_load_into_the_workspace(): void
    {
        $this->estate();
        $svc = app(StudioService::class);
        $ctx = $this->ctx();

        $sales = $svc->preset($ctx, 'sales');
        $this->assertContains('add:ADD-ME', array_column($sales['changes'], 'key'));
        $lean = $svc->preset($ctx, 'lean');
        $this->assertLessThanOrEqual(11, $lean['proposed_count'], 'lean: no more products than today');
        $this->assertSame('working_capital', $lean['inputs']['objective']);

        // Loaded into the workspace, the preset's picks give the same figures.
        $again = $svc->simulate($ctx, $sales['inputs']);
        $this->assertSame($sales['proposed_count'], $again['proposed_count']);
        $this->assertEqualsWithDelta($sales['summary']['sales'][1], $again['summary']['sales'][1], 1.0);
    }

    public function test_products_similar_stores_carry_are_offered_as_the_persons_own_change(): void
    {
        $this->estate();
        $ctx = $this->ctx();
        $this->assertArrayHasKey('RARE', $ctx['others'], 'two similar stores carry it; the engine does not propose it');
        $this->assertArrayNotHasKey('ADD-ME', $ctx['others']);

        $r = $this->sim(['picks' => [['kind' => 'add', 'sku' => 'RARE']]]);
        $row = $this->row($r, 'add:RARE');
        $this->assertSame('user', $row['source']);
        $this->assertSame(0.3, $row['confidence']);
        $this->assertSame(12, $r['proposed_count']);
    }

    public function test_the_product_panel_says_why_who_substitutes_and_what_happened_before(): void
    {
        $this->estate();
        $svc = app(StudioService::class);
        $ctx = $this->ctx();
        $p = $svc->product($ctx, $svc->simulate($ctx, StudioService::blank()), 'DUD');

        $this->assertTrue($p['on_today']);
        $this->assertSame('delist', $p['engine']['kind']);
        $this->assertNotEmpty($p['engine']['explanation']);
        $this->assertStringNotContainsString('range_delist', implode(' ', $p['engine']['explanation']), 'reasons in words, not internal fields');
        $this->assertNotNull($p['transfer']['mid'], 'who takes its buyers');
        $this->assertNotEmpty($p['transfer']['pairs']);
        $this->assertSame(6, $p['peers']['carrying'], 'every store in the peer group carries it, this one included');
        $this->assertSame(0, $p['past']['success'] + $p['past']['partial'] + $p['past']['failure']);

        AssortmentGap::where('tenant_id', $this->tenant->id)->where('sku', 'SHIELD')->update([
            'measured_at' => now(), 'measurement' => json_encode(['verdict' => 'success'])]);
        $p = $svc->product($ctx, $svc->simulate($ctx, StudioService::blank()), 'DUD');
        $this->assertSame(1, $p['past']['success'], 'a measured delist in the same category');
    }

    public function test_scenarios_are_saved_with_their_result_and_compare_as_saved(): void
    {
        $this->estate();
        $svc = app(StudioService::class);
        $ctx = $this->ctx();
        $s = $svc->save($ctx, $this->admin, ['picks' => [['kind' => 'add', 'sku' => 'ADD-ME']]] + StudioService::blank(), 'Yogurt in');

        $this->assertSame('Yogurt in', $s->name);
        $this->assertSame(12, $s->result['summary']['count']);
        $this->assertSame($this->asOf, $s->as_of_date->toDateString());
        $this->assertSame([$s->id], $svc->scenarios($ctx)->pluck('id')->all());
        $this->assertGreaterThan(0, $s->result['summary']['gained'][1]);
    }

    public function test_a_tried_range_becomes_the_plan_is_accepted_as_one_task_and_survives_the_night(): void
    {
        $this->estate();
        $svc = app(StudioService::class);

        // While plans are in review only an admin can make one, and it stays in review.
        $analyst = $this->createUser($this->tenant);
        try {
            $svc->makePlan($this->tenant, $analyst, $this->ctx(), ['picks' => [['kind' => 'add', 'sku' => 'ADD-ME']]] + StudioService::blank());
            $this->fail('a plan should not be made by a non-admin while plans are in review');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->goLive();
        $engine = AssortmentPlan::where('tenant_id', $this->tenant->id)->where('store_id', $this->stores[1])->where('category', 'Dairy')->first();
        $this->assertSame(AssortmentPlan::SOURCE_ENGINE, $engine->source);

        $plan = $svc->makePlan($this->tenant, $this->admin, $this->ctx(), ['picks' => [
            ['kind' => 'add', 'sku' => 'ADD-ME'], ['kind' => 'delist', 'sku' => 'BASE-1'],
        ]] + StudioService::blank(), 'Yogurt for a slow line');

        $this->assertSame(AssortmentPlan::SOURCE_STUDIO, $plan->source);
        $this->assertSame(AssortmentPlan::STATUS_PROPOSED, $plan->status);
        $this->assertSame('Yogurt for a slow line', $plan->scenario->name);
        $this->assertSame('simulated', $plan->impact['basis']);
        $this->assertNull(AssortmentPlan::find($engine->id), 'the engine plan for the shelf is replaced');

        // The nightly rebuild leaves the person's plan alone.
        $this->travel(1)->minutes();
        app(AssortmentEngine::class)->run($this->tenant);
        $open = AssortmentPlan::where('tenant_id', $this->tenant->id)->where('store_id', $this->stores[1])->where('category', 'Dairy')->get();
        $this->assertSame([$plan->id], $open->pluck('id')->all());

        // Accepted whole: one reset task; the engine's add is accepted, the person's delist rides on the plan.
        $owner = $this->createUser($this->tenant);
        app(PlanService::class)->accept($plan->fresh(), $this->admin, $owner->id, now()->addDays(7)->toDateString());
        $this->assertSame(AssortmentPlan::STATUS_ACCEPTED, $plan->fresh()->status);
        $this->assertSame(AssortmentGap::STATUS_ACCEPTED, AssortmentGap::where('tenant_id', $this->tenant->id)->where('store_id', $this->stores[1])->where('sku', 'ADD-ME')->value('status'));
        $titles = DB::table('notifications')->where('notifiable_id', $owner->id)->pluck('data')->map(fn ($d) => json_decode($d, true)['title'] ?? '');
        $this->assertCount(1, $titles->filter(fn ($t) => str_starts_with($t, 'Range reset for you')));

        // A plan of the person's own changes only is accepted too (no engine decision behind it).
        $this->travel(1)->minutes();
        $other = $svc->makePlan($this->tenant, $this->admin, app(StudioService::class)->context($this->tenant, $this->stores[2], 'Dairy'),
            ['picks' => [['kind' => 'delist', 'sku' => 'BASE-2']]] + StudioService::blank());
        app(PlanService::class)->accept($other, $this->admin, $owner->id);
        $this->assertSame(AssortmentPlan::STATUS_ACCEPTED, $other->fresh()->status);

        // A shelf being carried out cannot get a second plan.
        $this->expectException(HttpException::class);
        $svc->makePlan($this->tenant, $this->admin, app(StudioService::class)->context($this->tenant, $this->stores[1], 'Dairy'),
            ['picks' => [['kind' => 'delist', 'sku' => 'BASE-4']]] + StudioService::blank());
    }

    public function test_the_studio_screen_works_as_one_workspace(): void
    {
        $this->estate();
        $this->goLive();

        $this->get(AssortmentStudio::getUrl(tenant: $this->tenant))->assertOk()
            ->assertSee('The range today')->assertSee('Goat kefir')->assertSee('Problems and opportunities')->assertSee('Sheep yogurt')
            ->assertSee("remove('BASE-1')", false)->assertDontSee('@js(', false);

        $lw = Livewire::test(AssortmentStudio::class)
            ->assertSet('store', $this->stores[1])->assertSet('category', 'Dairy')
            ->call('remove', 'DUD')
            ->assertSet('picks', [['kind' => 'delist', 'sku' => 'DUD']])
            ->assertSee('Taken off')->assertSee('If it goes')
            ->set('objective', 'margin')->assertHasNoErrors()
            ->call('remove', 'OUTAGE')->assertSee('Not applied')
            ->call('add', 'DUD')->assertSet('picks', [['kind' => 'delist', 'sku' => 'OUTAGE']], 'taking it back undoes the pick')
            ->call('more')->assertSet('limit', 140)
            ->call('loadPreset', 'sales')
            ->assertSet('loaded', 'Sales max');
        $this->assertContains(['kind' => 'add', 'sku' => 'ADD-ME'], $lw->get('picks'));

        $lw->set('tab', 'compare')->assertSee('Scenarios side by side')->assertSee('High availability')->assertSee('Lean range')
            ->callAction('save', ['name' => 'Try one'])->assertHasNoActionErrors();
        $this->assertSame(1, AssortmentScenario::where('tenant_id', $this->tenant->id)->where('name', 'Try one')->count());

        $lw->set('tab', 'workspace')->callAction('plan', ['name' => 'From the Studio'])->assertHasNoActionErrors();
        $plan = AssortmentPlan::where('tenant_id', $this->tenant->id)->where('source', AssortmentPlan::SOURCE_STUDIO)->firstOrFail();
        $lw->assertRedirect(AssortmentPlanPage::getUrl(['plan' => $plan->id]));

        $this->get(AssortmentPlanPage::getUrl(['plan' => $plan->id], tenant: $this->tenant))->assertOk()
            ->assertSee('made in the Decision Studio')->assertSee('Open in the Decision Studio')->assertSee('Simulated');

        // A store outside the list is refused.
        $this->get(AssortmentStudio::getUrl(['store' => 999999], tenant: $this->tenant))->assertNotFound();
    }

    public function test_who_sees_the_studio(): void
    {
        $this->estate();
        $analyst = $this->createUser($this->tenant);
        $this->actingAs($analyst);
        $this->get(AssortmentStudio::getUrl(tenant: $this->tenant))->assertOk()->assertSee('opens when range decisions go live');

        $analyst->forceFill(['visible_screens' => ['assortment_plans']])->save();
        $this->get(AssortmentStudio::getUrl(tenant: $this->tenant))->assertForbidden();

        $analyst->forceFill(['visible_screens' => null])->save();
        $this->actingAs($this->admin);
        $this->goLive();
        $this->actingAs($analyst->fresh());
        $this->get(AssortmentStudio::getUrl(tenant: $this->tenant))->assertOk()->assertSee('The range today')->assertSee('Make this the plan');
    }
}
