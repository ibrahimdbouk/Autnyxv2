<?php

namespace Tests\Feature;

use App\Filament\Ops\Pages\Usage;
use App\Filament\Pages\AssortmentBriefingPage;
use App\Filament\Pages\AssortmentPlanPage;
use App\Filament\Pages\AssortmentStudio;
use App\Models\AssortmentBriefing;
use App\Models\AssortmentGap;
use App\Models\AssortmentPlan;
use App\Models\Tenant;
use App\Models\UsageMeter;
use App\Models\User;
use App\Services\AI\AiBudget;
use App\Services\AI\AiResult;
use App\Services\AI\AnthropicClient;
use App\Services\Assortment\AssortmentEngine;
use App\Services\Assortment\AssortmentUploads;
use App\Services\Assortment\AssortmentUsage;
use App\Services\Assortment\BriefingService;
use App\Services\Assortment\OutcomeMeasurer;
use App\Services\Assortment\PlanService;
use App\Services\Assortment\ShelfSpace;
use App\Services\Assortment\ShelfStrip;
use App\Services\Assortment\ValidationGate;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Concerns\SeedsAssortmentEstate;
use Tests\TestCase;

/**
 * v2.1 — prove and show: holdout stores, the schematic shelf strip, the weekly
 * briefing (rules decide, AI only narrates) and usage metering.
 */
class AssortmentV21Test extends TestCase
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

        return $this->storePlan(1);
    }

    private function storePlan(int $n): ?AssortmentPlan
    {
        return AssortmentPlan::where('tenant_id', $this->tenant->id)->where('store_id', $this->stores[$n])->where('category', 'Dairy')
            ->whereNotIn('status', [AssortmentPlan::STATUS_REJECTED])->latest('id')->first();
    }

    private function csv(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'v21') . '.csv';
        file_put_contents($path, $body);

        return $path;
    }

    // ── Holdout stores ────────────────────────────────────────────────────────

    public function test_holdouts_are_suggested_kept_back_and_released(): void
    {
        $plan = $this->live();
        $svc = app(PlanService::class);

        $cands = $svc->holdoutCandidates($plan);
        $ids = array_column($cands, 'store_id');
        $this->assertNotContains($this->stores[1], $ids, 'the plan\'s own store is never its comparison');
        $this->assertCount(5, $cands);
        $this->assertStringContainsString('Dairy sales', $cands[0]['why']);

        $held = array_slice($ids, 0, 3);
        $svc->accept($plan, $this->admin, holdouts: $held);
        $plan->refresh();
        $this->assertTrue($plan->holding());
        $this->assertSame($held, $plan->holdoutIds());

        try {
            $svc->accept($plan->replicate()->fill(['status' => AssortmentPlan::STATUS_PROPOSED]), $this->admin, holdouts: [$this->stores[1]]);
            $this->fail('a store not free to hold back is refused');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        // A plan at a held-back store cannot be accepted in that category while it is held.
        $other = $plan->replicate(['holdouts'])->fill(['store_id' => $held[0], 'status' => AssortmentPlan::STATUS_PROPOSED, 'decided_at' => null, 'decided_by' => null]);
        $other->save();
        $this->assertNotContains($held[1], array_column($svc->holdoutCandidates($other), 'store_id'), 'a store held back for one plan is not offered to another');
        try {
            $svc->accept($other, $this->admin);
            $this->fail('a held-back store must not reset the category');
        } catch (HttpException $e) {
            $this->assertStringContainsString('held back in Dairy as the comparison for range plan #' . $plan->id, $e->getMessage());
        }
        $this->get(AssortmentPlanPage::getUrl(['plan' => $other->id], tenant: $this->tenant))->assertOk()->assertSee('Held back for comparison');
        $this->get(AssortmentPlanPage::getUrl(['plan' => $plan->id], tenant: $this->tenant))->assertOk()
            ->assertSee('Held back for comparison')->assertSee('Store ' . array_search($held[0], $this->stores, true));

        Livewire::test(AssortmentPlanPage::class, ['plan' => $plan->id])
            ->callAction('release', ['stores' => [$held[0]]])->assertHasNoActionErrors();
        $this->assertSame(array_slice($held, 1), $plan->fresh()->holdoutIds());
        $this->assertNull($svc->heldBy($other));
    }

    public function test_the_accept_form_ticks_the_closest_matches(): void
    {
        $plan = $this->live();
        $top = array_column(array_slice(app(PlanService::class)->holdoutCandidates($plan), 0, 3), 'store_id');

        Livewire::test(AssortmentPlanPage::class, ['plan' => $plan->id])
            ->mountAction('accept')->assertActionDataSet(['holdouts' => $top])
            ->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame($top, $plan->fresh()->holdoutIds());
    }

    public function test_a_plan_is_measured_against_its_holdouts(): void
    {
        $plan = $this->live();
        $svc = app(PlanService::class);
        $held = array_column(array_slice($svc->holdoutCandidates($plan), 0, 3), 'store_id');
        $svc->accept($plan, $this->admin, holdouts: $held);
        $doneOn = Carbon::parse($this->asOf)->subDays(60);
        $svc->markDone($plan->fresh(), $this->admin, $doneOn->toDateString());
        $rows = [];
        for ($d = 1; $d <= 56; $d++) {
            $rows[] = ['tenant_id' => $this->tenant->id, 'store_id' => $this->stores[1], 'sku' => 'ADD-ME', 'date' => $doneOn->copy()->addDays($d)->toDateString(),
                'units_sold' => 3, 'revenue' => 30, 'transaction_count' => 1, 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('sales_daily')->insert($rows);
        app(OutcomeMeasurer::class)->measureDue($this->tenant->id);

        $m = $plan->fresh()->measurement;
        $this->assertSame('holdouts', $m['control_basis']);
        $this->assertSame(3, $m['control_stores']);
        $this->assertSame('measured', $m['strength']);
        $this->assertSame(3, $m['holdout']['stores']);
        $this->assertGreaterThanOrEqual(3, $m['peers']['control_stores']);
        $this->assertEqualsWithDelta(56 * 30, $m['uplift'], 60);

        // A held-back store that changed the category anyway is left out; with 2 left, the peers are the result.
        $gap = AssortmentGap::where('tenant_id', $this->tenant->id)->where('store_id', $this->stores[1])->where('sku', 'ADD-ME')->first()->replicate();
        $gap->forceFill(['store_id' => $held[0], 'status' => AssortmentGap::STATUS_ACCEPTED, 'done_at' => $doneOn->copy()->addDays(3)])->save();
        $m = app(OutcomeMeasurer::class)->measurePlan($plan->fresh(), 56);
        $this->assertSame('peers', $m['control_basis']);
        $this->assertSame(1, $m['holdout']['left_out']);
        $this->assertSame(2, $m['holdout']['stores']);

        $this->get(AssortmentPlanPage::getUrl(['plan' => $plan->id], tenant: $this->tenant))->assertOk()
            ->assertSee('the 3 stores held back for it')->assertSee('Against all');
    }

    // ── The schematic shelf strip ─────────────────────────────────────────────

    public function test_the_strip_draws_today_against_the_plan(): void
    {
        $products = ['A' => ['name' => 'Alpha', 'subcategory' => 'X'], 'B' => ['name' => 'Beta', 'subcategory' => 'X'],
            'C' => ['name' => 'Gamma', 'subcategory' => 'X'], 'D' => ['name' => 'Delta', 'subcategory' => 'X']];
        $shelf = ['skus' => [
            'A' => ['sales' => 100, 'width' => 0.1, 'facings' => 2], 'B' => ['sales' => 50, 'width' => 0.1, 'facings' => 3],
            'C' => ['sales' => 10, 'width' => 0.1, 'facings' => 1],
        ], 'space' => ['basis' => ShelfSpace::BASIS_FACINGS, 'metres' => 1.0, 'entry' => ['D' => ['width' => 0.1, 'facings' => 2]]]];

        $s = ShelfStrip::build($shelf, $products, ['A', 'B', 'D'], ['B' => 2]);
        $this->assertEqualsWithDelta(0.6, $s['used_today'], 1e-9);
        $this->assertEqualsWithDelta(0.6, $s['used_plan'], 1e-9);
        $this->assertSame(['A' => 'keep', 'B' => 'keep', 'C' => 'delist'], collect($s['today'])->pluck('state', 'sku')->all());
        $this->assertSame(['A' => 'keep', 'B' => 'cut', 'D' => 'add'], collect($s['plan'])->pluck('state', 'sku')->all());
        $this->assertSame(['add' => 1, 'delist' => 1, 'cut' => 1, 'grow' => 0], $s['counts']);
        $this->assertEqualsWithDelta(0.4, collect($s['plan'])->firstWhere('sku', 'D')['x'], 1e-9, 'one order, by sales, new products last');

        $shelf['space'] = ['basis' => ShelfSpace::BASIS_COUNT, 'metres' => 2.0];
        $e = ShelfStrip::build($shelf, $products, ['A', 'B'], []);
        $this->assertTrue($e['equal']);
        $this->assertEqualsWithDelta(2 / 3, $e['today'][0]['metres'], 1e-4);

        unset($shelf['space']['metres']);
        $this->assertNull(ShelfStrip::build($shelf, $products, ['A'], []), 'no shelf metres, no strip');

        $html = view('components.ui.shelf-strip', ['strip' => $s])->render();
        $this->assertStringContainsString('ax-strip-block--add', $html);
        $this->assertStringContainsString('ax-strip-block--cut', $html);
        $this->assertStringContainsString('Schematic, not a planogram', $html);
        $this->assertStringNotContainsString('style=', $html);
    }

    public function test_the_plan_page_and_the_studio_show_the_shelf(): void
    {
        $this->seedEstate();
        $up = app(AssortmentUploads::class);
        $up->importShelfSpace($this->tenant->id, $this->csv("store,category,metres\nS1,Dairy,2.4\n"));
        $lines = "sku,width,facings\nADD-ME,10,2\nOUTAGE,10,2\nDUD,10,3\nSHIELD,10,3\n";
        for ($n = 1; $n <= 8; $n++) {
            $lines .= "BASE-{$n},10,2\n";
        }
        $up->importProductSpace($this->tenant->id, $this->csv($lines));
        app(AssortmentEngine::class)->run($this->tenant);
        $this->goLive();
        $plan = $this->storePlan(1);

        $strip = app(ShelfStrip::class)->forPlan($plan, []);
        $this->assertSame(2.4, $strip['metres']);
        $this->assertContains('ADD-ME', collect($strip['plan'])->where('state', 'add')->pluck('sku')->all());
        $this->assertLessThanOrEqual(2.4 + 1e-6, $strip['used_plan']);

        $this->get(AssortmentPlanPage::getUrl(['plan' => $plan->id], tenant: $this->tenant))->assertOk()
            ->assertSee('The shelf, today and with the plan')->assertSee('Schematic, not a planogram');
        // Unticking the add takes it off the drawn plan.
        $add = collect($plan->actionable())->firstWhere('kind', 'add');
        $after = app(ShelfStrip::class)->forPlan($plan, [$add['key']]);
        $this->assertNotContains('ADD-ME', collect($after['plan'])->pluck('sku')->all());

        Livewire::test(AssortmentStudio::class, ['store' => $this->stores[1], 'category' => 'Dairy'])
            ->assertSee('The shelf, today and in this workspace');

        // Store 2 has no shelf metres: no strip.
        $this->assertNull(app(ShelfStrip::class)->forPlan($this->storePlan(2) ?? $plan->replicate()->fill(['store_id' => $this->stores[2]]), []));
    }

    // ── The weekly briefing ───────────────────────────────────────────────────

    public function test_the_briefing_is_built_by_the_rules_and_sent_once(): void
    {
        $this->seedEstate();
        app(AssortmentEngine::class)->run($this->tenant);
        $this->assertNull(app(BriefingService::class)->generate($this->tenant, false), 'nothing before the tenant is live');
        $this->goLive();

        $b = app(BriefingService::class)->generate($this->tenant, false);
        $f = $b->content['facts'];
        $open = AssortmentGap::where('tenant_id', $this->tenant->id)->where('status', AssortmentGap::STATUS_OPEN)->count();
        $this->assertSame($open, $f['open_decisions']);
        $this->assertSame($open, $f['new_decisions'], 'every decision is new in the first week');
        $this->assertLessThanOrEqual(BriefingService::TOP, count($b->content['top']));
        $this->assertGreaterThan(0, $f['plans_waiting']);
        $this->assertStringContainsString('new range decision', $b->headline());
        $this->assertFalse($b->narrated());
        $this->assertNotEmpty($b->content['focus']);

        $this->artisan('assortment:briefing', ['--tenant' => $this->tenant->id, '--no-ai' => true])->assertSuccessful();
        $this->artisan('assortment:briefing', ['--tenant' => $this->tenant->id, '--no-ai' => true])->assertSuccessful();
        $this->assertSame(1, AssortmentBriefing::where('tenant_id', $this->tenant->id)->count(), 'one briefing a week');
        $sent = DB::table('notifications')->where('notifiable_id', $this->admin->id)->pluck('data')
            ->filter(fn ($d) => str_starts_with(json_decode($d, true)['title'] ?? '', 'Assortment briefing:'));
        $this->assertCount(1, $sent, 'sent once a week');
        $this->assertNotNull(AssortmentBriefing::first()->sent_at);
    }

    public function test_ai_only_narrates_and_cannot_add_numbers(): void
    {
        $this->live();
        $fake = fn (string $text) => new class(app(AiBudget::class), $text) extends AnthropicClient {
            public function __construct(AiBudget $b, private string $text)
            {
                parent::__construct($b);
            }

            public function message(?int $tenantId, string $feature, string $prompt, string $tier = 'fast', int $maxTokens = 1024, ?string $system = null, int $timeout = 60): AiResult
            {
                return new AiResult(true, $this->text, null, 'test-model');
            }
        };
        $svc = fn (string $text) => new BriefingService($fake($text));
        $open = AssortmentGap::where('tenant_id', $this->tenant->id)->where('status', AssortmentGap::STATUS_OPEN)->count();

        $b = $svc(json_encode(['headline' => "A busy week: {$open} range decisions to make", 'summary' => 'Start with the top of the list.']))->generate($this->tenant);
        $this->assertTrue($b->narrated());
        $this->assertSame('test-model', $b->narrated_model);
        $this->assertStringStartsWith('A busy week', $b->headline());

        $b = $svc(json_encode(['headline' => 'Sales will rise 37% next month', 'summary' => 'Trust us.']))->generate($this->tenant);
        $this->assertFalse($b->narrated(), 'a number not in the facts drops the AI words');
        $this->assertStringContainsString('new range decision', $b->headline());
    }

    public function test_the_briefing_page_is_widgets_for_head_office(): void
    {
        $this->live();
        $this->get(AssortmentBriefingPage::getUrl(tenant: $this->tenant))->assertOk()->assertSee('No briefing yet');
        Livewire::test(AssortmentBriefingPage::class)->callAction('rebuild');
        $b = AssortmentBriefing::firstOrFail();

        $this->get(AssortmentBriefingPage::getUrl(tenant: $this->tenant))->assertOk()
            ->assertSee($b->headline())->assertSee('The week in figures')->assertSee('Worth deciding now')->assertSee('Range plans to review')
            ->assertSee('Written by the rules');

        $store = $this->createUser($this->tenant);
        $store->forceFill(['org_role' => User::ORG_STORE_MANAGER])->save();
        $this->actingAs($store);
        $this->get(AssortmentBriefingPage::getUrl(tenant: $this->tenant))->assertForbidden();
    }

    // ── Usage metering ────────────────────────────────────────────────────────

    public function test_usage_is_rebuilt_from_the_records(): void
    {
        $plan = $this->live();
        app(PlanService::class)->accept($plan, $this->admin);
        app(BriefingService::class)->generate($this->tenant, false);

        $usage = app(AssortmentUsage::class);
        $period = now()->format('Y-m');
        $c = $usage->rebuild($this->tenant->id, $period);
        $this->assertSame(1, $c['runs']);
        $this->assertSame(6, $c['stores']);
        $this->assertSame(AssortmentGap::where('tenant_id', $this->tenant->id)->where('status', '<>', AssortmentGap::STATUS_SHADOW)->count(), $c['decisions_proposed']);
        $this->assertSame(AssortmentGap::where('tenant_id', $this->tenant->id)->where('status', AssortmentGap::STATUS_ACCEPTED)->count(), $c['decisions_accepted']);
        $this->assertSame(1, $c['plans_accepted']);
        $this->assertSame(1, $c['briefings']);
        $this->assertSame($c, $usage->rebuild($this->tenant->id, $period), 'the same records give the same counts');
        $this->assertSame(1, (int) UsageMeter::where('tenant_id', $this->tenant->id)->where('app', 'assortment')->where('metric', 'plans_accepted')->value('count'));
        $this->assertSame(0, $usage->count($this->tenant->id, now()->subMonths(3)->format('Y-m'))['runs']);

        $this->artisan('assortment:usage', ['--tenant' => $this->tenant->id])->assertSuccessful();
        $this->artisan('assortment:usage', ['--period' => '2026-13'])->assertFailed();
    }

    public function test_the_ops_usage_page_is_a_table_with_stats(): void
    {
        $this->live();
        app(AssortmentUsage::class)->rebuild($this->tenant->id);

        auth()->logout();   // only a super admin (or the console) may make one
        $super = User::factory()->superAdmin()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($super);
        Filament::setTenant(null);
        Filament::setCurrentPanel(Filament::getPanel('ops'));
        $this->get(Usage::getUrl(panel: 'ops'))->assertOk()
            ->assertSee('Usage in ' . now()->format('F Y'))->assertSee('Range decisions accepted')->assertSee('Assortment runs');

        Livewire::test(Usage::class)
            ->assertCanSeeTableRecords(UsageMeter::where('tenant_id', $this->tenant->id)->get())
            ->filterTable('period', '2020-01')->assertCountTableRecords(0)
            ->callAction('rebuild')->assertNotified();

        $this->actingAs($this->admin);
        $this->get(Usage::getUrl(panel: 'ops'))->assertForbidden();
    }
}
