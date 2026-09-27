<?php

namespace Tests\Feature;

use App\Filament\Pages\AssortmentDecision;
use App\Filament\Pages\AssortmentValidation;
use App\Filament\Pages\GettingStarted;
use App\Models\ApiKey;
use App\Models\AssortmentGap;
use App\Models\DecisionCase;
use App\Models\Investigation;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Linking\SubjectLinks;
use App\Services\Assortment\AssortmentEngine;
use App\Services\Assortment\DecisionLearning;
use App\Services\Assortment\DecisionService;
use App\Services\Assortment\OutcomeMeasurer;
use App\Services\Assortment\ValidationGate;
use App\Services\Onboarding\OnboardingService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\SeedsAssortmentEstate;
use Tests\TestCase;

/**
 * v1 gaps 1–4 and 6: notifications, the Root Cause link through the platform,
 * getting started, learning from results (decision memory), and the export.
 */
class AssortmentLoopTest extends TestCase
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

    private function gap(string $sku, string $type, int $store = 1): ?AssortmentGap
    {
        return AssortmentGap::where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->stores[$store])->where('sku', $sku)->where('type', $type)->first();
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
        $this->tenant = $this->tenant->fresh();
    }

    private function notices(User $u): \Illuminate\Support\Collection
    {
        return DB::table('notifications')->where('notifiable_id', $u->id)->pluck('data')
            ->map(fn ($d) => json_decode($d, true)['title'] ?? '');
    }

    // ── Gap 1: notifications ──────────────────────────────────────────────────

    public function test_a_live_run_announces_new_decisions_once_and_shadow_runs_stay_quiet(): void
    {
        $this->seedEstate();
        app(AssortmentEngine::class)->run($this->tenant);
        $this->assertCount(0, $this->notices($this->admin), 'shadow: nobody hears anything');

        $this->goLive();
        AssortmentGap::where('tenant_id', $this->tenant->id)->delete();
        app(AssortmentEngine::class)->run($this->tenant);
        $this->assertCount(1, $this->notices($this->admin)->filter(fn ($t) => str_contains($t, 'new range decision')));

        $this->travel(1)->minutes();
        app(AssortmentEngine::class)->run($this->tenant);
        $this->assertCount(1, $this->notices($this->admin)->filter(fn ($t) => str_contains($t, 'new range decision')), 're-found decisions are not new');
    }

    public function test_the_task_owner_hears_about_it_and_is_reminded_once(): void
    {
        $this->seedEstate();
        app(AssortmentEngine::class)->run($this->tenant);
        $this->goLive();
        $owner = $this->createUser($this->tenant);

        $add = $this->gap('ADD-ME', AssortmentGap::TYPE_ADD);
        app(DecisionService::class)->accept($add, $this->admin, $owner->id, now()->addDay()->toDateString());
        $this->assertTrue($this->notices($owner)->contains(fn ($t) => str_starts_with($t, 'Range task for you')));

        $this->artisan('assortment:remind', ['--tenant' => $this->tenant->id])->assertSuccessful();
        $this->artisan('assortment:remind', ['--tenant' => $this->tenant->id])->assertSuccessful();
        $this->assertCount(1, $this->notices($owner)->filter(fn ($t) => str_starts_with($t, 'Range task due')), 'reminded once');

        $this->travel(3)->days();
        $this->artisan('assortment:remind', ['--tenant' => $this->tenant->id])->assertSuccessful();
        $this->artisan('assortment:remind', ['--tenant' => $this->tenant->id])->assertSuccessful();
        $this->assertCount(1, $this->notices($owner)->filter(fn ($t) => str_starts_with($t, 'Overdue range task')), 'overdue once');
    }

    // ── Gap 4: learning from results ──────────────────────────────────────────

    public function test_decisions_and_their_results_go_into_decision_memory(): void
    {
        $this->seedEstate();
        app(AssortmentEngine::class)->run($this->tenant);
        $this->goLive();
        $svc = app(DecisionService::class);

        $add = $this->gap('ADD-ME', AssortmentGap::TYPE_ADD);
        $svc->accept($add, $this->admin);
        $svc->reject($this->gap('DUD', AssortmentGap::TYPE_DELIST), $this->admin, 'Contract');

        $case = DecisionCase::where('action_ref', DecisionLearning::ref($add))->first();
        $this->assertNotNull($case);
        $this->assertSame('range_add', $case->intent_type);
        $this->assertSame(DecisionCase::DECISION_ADOPTED, $case->decision);
        $this->assertEqualsWithDelta((float) $add->evidence['expected_sales_change_per_year'], $case->expected_value, 0.01, 'expected in sales, the unit it is measured in');
        $this->assertSame($add->evidence['transfer']['version'], $case->evidence['transfer']['version']);
        $this->assertSame(1, DecisionCase::where('tenant_id', $this->tenant->id)->where('decision', DecisionCase::DECISION_REJECTED)->count());

        // Done 60 days ago; store 1 then sells the new product (AED 30 a day) on top of its usual dairy.
        $doneOn = Carbon::parse($this->asOf)->subDays(60);
        $svc->markDone($add->fresh(), $this->admin, 'Listed', $doneOn->toDateString());
        $rows = [];
        for ($d = 1; $d <= 56; $d++) {
            $rows[] = ['tenant_id' => $this->tenant->id, 'store_id' => $this->stores[1], 'sku' => 'ADD-ME', 'date' => $doneOn->copy()->addDays($d)->toDateString(),
                'units_sold' => 3, 'revenue' => 30, 'transaction_count' => 1, 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('sales_daily')->insert($rows);
        app(OutcomeMeasurer::class)->measureDue($this->tenant->id);

        $m = $add->fresh()->measurement;
        $this->assertSame($add->evidence['expected_sales_change_per_year'], $m['expected_per_year']);
        $this->assertEqualsWithDelta($m['uplift_per_year'] - $m['expected_per_year'], $m['error_per_year'], 0.01);
        $this->assertSame('success', $m['verdict'], 'sales rose by more than half of what was estimated');
        $this->assertTrue($this->notices($this->admin)->contains(fn ($t) => str_starts_with($t, 'Result measured')));

        $case->refresh();
        $this->assertSame(DecisionCase::OUTCOME_SUCCESS, $case->outcome_status);
        $this->assertEqualsWithDelta($m['uplift_per_year'], $case->realized_value, 0.01);

        $perf = app(DecisionLearning::class)->performance($this->tenant->id);
        $this->assertSame(1, $perf['add']['measured']);
        $this->assertSame(1.0, $perf['add']['worked_rate']);
        $this->assertSame(0.0, $perf['delist']['accept_rate']);
        $this->get(AssortmentValidation::getUrl(tenant: $this->tenant))->assertOk()->assertSee('How the rules are doing');
    }

    public function test_verdicts_follow_the_rule_for_each_type(): void
    {
        $m = app(OutcomeMeasurer::class);
        $this->assertSame('success', $m->verdict('add', 600, 1000, 'measured'));
        $this->assertSame('partial', $m->verdict('add', 100, 1000, 'measured'));
        $this->assertSame('failure', $m->verdict('add', -50, 1000, 'measured'));
        $this->assertSame('partial', $m->verdict('add', 900, 1000, 'weak'), 'few comparison stores: at best partly');
        $this->assertSame('success', $m->verdict('delist', -800, -1000, 'measured'), 'lost less than estimated');
        $this->assertSame('partial', $m->verdict('delist', -1800, -1000, 'measured'));
        $this->assertSame('failure', $m->verdict('delist', -2500, -1000, 'measured'));
    }

    // ── Gap 2: Root Cause link, through the platform ──────────────────────────

    public function test_a_stockout_hidden_decision_links_to_the_open_investigation(): void
    {
        $this->seedEstate();
        app(AssortmentEngine::class)->run($this->tenant);
        $this->goLive();
        $out = $this->gap('OUTAGE', AssortmentGap::TYPE_STOCKOUT_HIDDEN);

        $this->get(AssortmentDecision::getUrl(['decision' => $out->id], tenant: $this->tenant))->assertOk()
            ->assertSee('Root Cause has no open investigation');

        $inv = Investigation::create(['tenant_id' => $this->tenant->id, 'title' => 'Oat milk keeps running out at Store 1',
            'status' => Investigation::STATUS_OPEN, 'primary_sku' => 'OUTAGE', 'primary_store_id' => $this->stores[1]]);

        $links = app(SubjectLinks::class)->for($this->tenant->id, $this->stores[1], 'OUTAGE');
        $this->assertCount(1, $links);
        $this->assertSame(Tenant::APP_ROOT_CAUSE, $links[0]['app']);
        $this->assertStringContainsString((string) $inv->id, (string) $links[0]['url']);

        $this->get(AssortmentDecision::getUrl(['decision' => $out->id], tenant: $this->tenant))->assertOk()
            ->assertSee('Open in Root Cause')->assertSee('Oat milk keeps running out at Store 1')
            ->assertSee('Where its buyers would go');

        // Without Root Cause the platform has nothing to say, and nothing breaks.
        $this->tenant->update(['apps' => [Tenant::APP_ASSORTMENT]]);
        $this->assertSame([], app(SubjectLinks::class)->for($this->tenant->id, $this->stores[1], 'OUTAGE'));
    }

    // ── Gap 3: getting started ────────────────────────────────────────────────

    public function test_getting_started_walks_an_admin_from_data_to_go_live(): void
    {
        $this->seedEstate();
        $steps = fn () => collect(app(OnboardingService::class)->sections($this->tenant->id))->firstWhere('key', Tenant::APP_ASSORTMENT)['steps'];

        $before = collect($steps())->keyBy('key');
        $this->assertFalse($before['range_run']['done']);
        $this->assertFalse($before['range_live']['done']);
        $this->assertTrue($before['range_promotions']['done']);

        app(AssortmentEngine::class)->run($this->tenant);
        $mid = collect($steps())->keyBy('key');
        $this->assertTrue($mid['range_run']['done']);
        $this->assertTrue($mid['range_overlap']['done'], 'every product sells in several stores');
        $this->assertFalse($mid['range_review']['done']);

        $this->goLive();
        $after = collect($steps())->keyBy('key');
        $this->assertTrue($after['range_review']['done']);
        $this->assertTrue($after['range_live']['done']);

        $this->get(GettingStarted::getUrl(tenant: $this->tenant))->assertOk()
            ->assertSee('Sales and stock from several stores for the same products')->assertSee('Open Validation');
    }

    public function test_one_store_per_product_is_flagged_as_nothing_to_compare(): void
    {
        $t = $this->tenant->id;
        foreach (range(1, 30) as $i) {
            $st = DB::table('stores')->insertGetId(['tenant_id' => $t, 'name' => "M{$i}", 'code' => "M{$i}", 'created_at' => now(), 'updated_at' => now()]);
            DB::table('assortment_store_ranges')->insert(['tenant_id' => $t, 'store_id' => $st, 'sku' => "ONLY-{$i}", 'carried' => true,
                'source' => 'inferred', 'as_of_date' => '2026-09-20', 'created_at' => now(), 'updated_at' => now()]);
        }
        $step = collect(collect(app(OnboardingService::class)->sections($t))->firstWhere('key', Tenant::APP_ASSORTMENT)['steps'])->firstWhere('key', 'range_overlap');

        $this->assertFalse($step['done']);
        $this->assertStringContainsString('0 of 30', $step['detail']);
        $this->assertStringContainsString('not a sample', $step['detail']);
    }

    // ── Gap 6: export ─────────────────────────────────────────────────────────

    public function test_range_decisions_are_exported_once_live_never_in_shadow(): void
    {
        $this->seedEstate();
        app(AssortmentEngine::class)->run($this->tenant);
        [, $token] = ApiKey::generate($this->tenant->id, 'bi', [ApiKey::SCOPE_READ_EXPORTS]);
        $h = ['X-Api-Key' => $token];

        $this->getJson('/api/v1/exports', $h)->assertOk()->assertJsonFragment(['dataset' => 'range_decisions']);
        $this->assertSame([], $this->getJson('/api/v1/exports/range_decisions', $h)->assertOk()->json('data'), 'shadow decisions stay private');

        $this->goLive();
        $rows = collect($this->getJson('/api/v1/exports/range_decisions', $h)->assertOk()->json('data'));
        $add = $rows->firstWhere('sku', 'ADD-ME');
        $this->assertSame('add', $add['type']);
        $this->assertSame('assumed', $add['transfer_basis']);
        $this->assertNotNull($add['expected_sales_change_per_year']);
        $this->assertSame('established', $add['lifecycle']);
    }
}
