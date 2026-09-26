<?php

namespace Tests\Feature;

use App\Filament\Pages\AssortmentCategoryReview;
use App\Filament\Pages\AssortmentDecision;
use App\Filament\Pages\AssortmentOutcomes;
use App\Filament\Pages\AssortmentSetup;
use App\Filament\Pages\AssortmentStoreProfile;
use App\Filament\Pages\AssortmentValidation;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\AssortmentDecisionResource;
use App\Models\AssortmentGap;
use App\Models\AssortmentMustStock;
use App\Models\AssortmentStoreRange;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Assortment\AssortmentEngine;
use App\Services\Assortment\AssortmentUploads;
use App\Services\Assortment\DecisionService;
use App\Services\Assortment\OutcomeMeasurer;
use App\Services\Assortment\TenantAssortment;
use App\Services\Assortment\ValidationGate;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\SeedsAssortmentEstate;
use Tests\TestCase;

/**
 * Assortment A3.5–A6 end to end: the validation gate, the screens, the
 * decide → task → measured-result loop, and the tenant's setup files.
 */
class AssortmentAppTest extends TestCase
{
    use SeedsAssortmentEstate;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant([
            'apps' => [Tenant::APP_ROOT_CAUSE, Tenant::APP_ASSORTMENT],
            'currency' => 'AED',
        ]);
    }

    private function enter(?User $as = null): User
    {
        $user = $as ?? $this->actingAsTenantAdmin($this->tenant);
        if ($as) {
            $this->actingAs($as);
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->tenant);

        return $user;
    }

    private function gap(string $sku, string $type, int $store = 1): ?AssortmentGap
    {
        return AssortmentGap::where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->stores[$store])->where('sku', $sku)->where('type', $type)->first();
    }

    /** Review every sampled decision with one verdict and go live. */
    private function passGate(User $user, string $verdict = AssortmentGap::VERDICT_SENSIBLE): bool
    {
        $gate = app(ValidationGate::class);
        foreach (array_keys(AssortmentGap::TYPES) as $type) {
            foreach ($gate->sample($this->tenant, $type) as $g) {
                $gate->review($g, $user, $verdict);
            }
        }

        return $gate->goLive($this->tenant->fresh(), $user);
    }

    // ── A3.5 validation gate ─────────────────────────────────────────────────

    public function test_the_gate_needs_every_sample_reviewed_and_mostly_sensible(): void
    {
        $this->seedEstate();
        $admin = $this->enter();
        app(AssortmentEngine::class)->run($this->tenant);
        $gate = app(ValidationGate::class);

        $this->assertFalse($gate->status($this->tenant)['passed']);
        $this->assertFalse($gate->goLive($this->tenant, $admin), 'refused before any review');

        $this->assertFalse($this->passGate($admin, AssortmentGap::VERDICT_NOT_SENSIBLE), 'a review that finds nonsense does not pass');
        $this->assertFalse(TenantAssortment::isLive($this->tenant->fresh()));

        $this->assertTrue($this->passGate($admin));
        $this->assertTrue(TenantAssortment::isLive($this->tenant->fresh()));
        $this->assertSame(0, AssortmentGap::where('tenant_id', $this->tenant->id)->where('status', AssortmentGap::STATUS_SHADOW)->count());
        $this->assertSame(AssortmentGap::STATUS_OPEN, $this->gap('ADD-ME', AssortmentGap::TYPE_ADD)->status);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $this->tenant->id, 'event_type' => 'assortment.live']);

        // After going live, new runs open decisions straight away and keep verdicts.
        app(AssortmentEngine::class)->run($this->tenant->fresh());
        $this->assertSame(AssortmentGap::STATUS_OPEN, $this->gap('ADD-ME', AssortmentGap::TYPE_ADD)->status);
        $this->assertSame(AssortmentGap::VERDICT_SENSIBLE, $this->gap('ADD-ME', AssortmentGap::TYPE_ADD)->review_verdict);

        $gate->pause($this->tenant->fresh(), $admin);
        $this->assertSame(AssortmentGap::STATUS_SHADOW, $this->gap('ADD-ME', AssortmentGap::TYPE_ADD)->status);
    }

    public function test_shadow_decisions_never_reach_the_decisions_screen(): void
    {
        $this->seedEstate();
        $this->enter();
        app(AssortmentEngine::class)->run($this->tenant);

        $this->assertSame(0, AssortmentDecisionResource::getEloquentQuery()->count());
        $this->get(AssortmentDecisionResource::getUrl('index', tenant: $this->tenant))
            ->assertOk()->assertSee('Range decisions are being checked')->assertDontSee('Greek yogurt');
    }

    // ── A4 screens ───────────────────────────────────────────────────────────

    public function test_every_assortment_screen_renders_once_live(): void
    {
        $this->seedEstate();
        $admin = $this->enter();
        app(AssortmentEngine::class)->run($this->tenant);
        $this->passGate($admin);
        $add = $this->gap('ADD-ME', AssortmentGap::TYPE_ADD);

        $this->get(AssortmentDecisionResource::getUrl('index', tenant: $this->tenant))->assertOk()->assertSee('Greek yogurt');
        $this->get(AssortmentDecision::getUrl(['decision' => $add->id], tenant: $this->tenant))
            ->assertOk()->assertSee('Add Greek yogurt at Store 1')->assertSee('The stores behind it')->assertSee('(this store)');
        $this->get(AssortmentCategoryReview::getUrl(tenant: $this->tenant))->assertOk()->assertSee('Dairy');
        $this->get(AssortmentStoreProfile::getUrl(['store' => $this->stores[1]], tenant: $this->tenant))
            ->assertOk()->assertSee('Core range carried')->assertSee('Supermarket');
        $this->get(AssortmentOutcomes::getUrl(tenant: $this->tenant))->assertOk()->assertSee('No accepted decisions yet');
        $this->get(AssortmentValidation::getUrl(tenant: $this->tenant))->assertOk()->assertSee('Assortment is live');
        $this->get(AssortmentValidation::getUrl(['type' => AssortmentGap::TYPE_DELIST], tenant: $this->tenant))
            ->assertOk()->assertSee('Goat kefir')->assertDontSee('Greek yogurt');
        $this->get(AssortmentSetup::getUrl(tenant: $this->tenant))->assertOk()->assertSee('Must-stock list');
        $this->get(Dashboard::getUrl(['app' => Tenant::APP_ASSORTMENT], tenant: $this->tenant))
            ->assertOk()->assertSee('Worth deciding now')->assertSee('Greek yogurt')->assertSee('Range health');
    }

    public function test_a_decision_from_another_tenant_is_not_found(): void
    {
        $this->seedEstate();
        $this->enter();
        app(AssortmentEngine::class)->run($this->tenant);
        $this->passGate(auth()->user());
        $id = $this->gap('ADD-ME', AssortmentGap::TYPE_ADD)->id;

        $other = $this->createTenant(['apps' => [Tenant::APP_ROOT_CAUSE, Tenant::APP_ASSORTMENT]]);
        $this->tenant = $other;
        $this->enter();
        $this->get(AssortmentDecision::getUrl(['decision' => $id], tenant: $other))->assertNotFound();
    }

    public function test_validation_and_setup_are_for_admins_and_nothing_opens_without_the_app(): void
    {
        $analyst = $this->createUser($this->tenant);
        $this->enter($analyst);
        $this->get(AssortmentValidation::getUrl(tenant: $this->tenant))->assertForbidden();
        $this->get(AssortmentSetup::getUrl(tenant: $this->tenant))->assertForbidden();
        $this->get(AssortmentOutcomes::getUrl(tenant: $this->tenant))->assertOk();

        $this->tenant->update(['apps' => [Tenant::APP_ROOT_CAUSE]]);
        $this->enter($analyst);
        $this->get(AssortmentOutcomes::getUrl(tenant: $this->tenant))->assertForbidden();
        $this->get(AssortmentDecisionResource::getUrl('index', tenant: $this->tenant))->assertForbidden();
    }

    public function test_the_category_review_pack_downloads(): void
    {
        $this->seedEstate();
        $this->enter();
        app(AssortmentEngine::class)->run($this->tenant);
        $this->passGate(auth()->user());

        $response = (new AssortmentCategoryReview())->downloadPack('Dairy');
        ob_start();
        $response->sendContent();
        $bytes = ob_get_clean();

        $this->assertStringStartsWith('PK', $bytes, 'an xlsx (zip) file');
        $this->assertStringContainsString('range-review-dairy', $response->headers->get('Content-Disposition'));
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $this->tenant->id, 'event_type' => \App\Models\AuditLog::EVENT_DATA_EXPORTED]);
    }

    // ── A5 decide → task → measured result ───────────────────────────────────

    public function test_accept_makes_a_task_and_reject_is_not_proposed_again(): void
    {
        $this->seedEstate();
        $admin = $this->enter();
        app(AssortmentEngine::class)->run($this->tenant);
        $this->passGate($admin);
        $svc = app(DecisionService::class);

        $add = $this->gap('ADD-ME', AssortmentGap::TYPE_ADD);
        $svc->accept($add, $admin, null, now()->addDays(7)->toDateString(), 'Order via buyer');
        $add->refresh();
        $this->assertSame(AssortmentGap::STATUS_ACCEPTED, $add->status);
        $this->assertSame(AssortmentGap::TASK_TO_DO, $add->task_status);
        $this->assertEqualsWithDelta($add->value_mid, $add->value_mid_at_decision, 0.01);

        $dud = $this->gap('DUD', AssortmentGap::TYPE_DELIST);
        $svc->reject($dud, $admin, 'Strategic or contract reason');

        app(AssortmentEngine::class)->run($this->tenant->fresh());
        $this->assertSame(AssortmentGap::STATUS_REJECTED, $this->gap('DUD', AssortmentGap::TYPE_DELIST)->status, 'a rejected call stays rejected');
        $this->assertSame(AssortmentGap::STATUS_ACCEPTED, $this->gap('ADD-ME', AssortmentGap::TYPE_ADD)->status);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $svc->accept($this->gap('DUD', AssortmentGap::TYPE_DELIST), $admin);
    }

    public function test_the_result_is_measured_against_peer_stores_that_did_not_change(): void
    {
        $this->seedEstate();
        $admin = $this->enter();
        app(AssortmentEngine::class)->run($this->tenant);
        $this->passGate($admin);

        $add = $this->gap('ADD-ME', AssortmentGap::TYPE_ADD);
        app(DecisionService::class)->accept($add, $admin);
        $doneOn = Carbon::parse($this->asOf)->subDays(60);
        app(DecisionService::class)->markDone($add->fresh(), $admin, 'Listed', $doneOn->toDateString());
        $this->assertSame($doneOn->copy()->addDays(56)->toDateString(), $add->fresh()->measure_after->toDateString());

        // After it was done, store 1 sells ADD-ME at 3 a day (AED 30) on top of its usual dairy.
        $rows = [];
        for ($d = 1; $d <= 56; $d++) {
            $rows[] = ['tenant_id' => $this->tenant->id, 'store_id' => $this->stores[1], 'sku' => 'ADD-ME',
                'date' => $doneOn->copy()->addDays($d)->toDateString(), 'units_sold' => 3, 'revenue' => 30,
                'transaction_count' => 1, 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('sales_daily')->insert($rows);

        $r = app(OutcomeMeasurer::class)->measureDue($this->tenant->id);
        $this->assertSame(1, $r['measured']);

        $m = $add->fresh()->measurement;
        $this->assertSame('category_sales', $m['metric']);
        $this->assertSame(5, $m['control_stores']);
        $this->assertEqualsWithDelta(1.0, $m['control_ratio'], 0.01, 'peers flat');
        $this->assertEqualsWithDelta(56 * 30, $m['uplift'], 60, 'the uplift is the new product\'s sales');
        $this->assertSame('measured', $m['strength']);

        $this->get(AssortmentOutcomes::getUrl(tenant: $this->tenant))->assertOk()->assertSee('Greek yogurt');
    }

    public function test_a_task_done_less_than_8_weeks_ago_waits(): void
    {
        $this->seedEstate();
        $admin = $this->enter();
        app(AssortmentEngine::class)->run($this->tenant);
        $this->passGate($admin);
        $add = $this->gap('ADD-ME', AssortmentGap::TYPE_ADD);
        app(DecisionService::class)->accept($add, $admin);
        app(DecisionService::class)->markDone($add->fresh(), $admin, null, Carbon::parse($this->asOf)->subDays(10)->toDateString());

        $this->assertSame(['measured' => 0, 'waiting' => 1], app(OutcomeMeasurer::class)->measureDue($this->tenant->id));
    }

    // ── A6 setup ─────────────────────────────────────────────────────────────

    public function test_a_range_file_marks_products_carried_or_not(): void
    {
        $this->seedEstate();
        $this->enter();
        // Store 1 is told it does NOT carry BASE-3 (it sells well at every peer) → proposed as an add.
        $path = tempnam(sys_get_temp_dir(), 'rng') . '.csv';
        file_put_contents($path, "Store Code,SKU,Carried\nS1,BASE-3,N\nS1,ADD-ME,Y\nNOPE,BASE-2,Y\n");

        $r = app(AssortmentUploads::class)->importRange($this->tenant->id, $path);
        $this->assertSame(1, $r['stores']);
        $this->assertSame(1, $r['not_carried']);
        $this->assertCount(1, $r['skipped']);

        app(AssortmentEngine::class)->run($this->tenant);

        $row = AssortmentStoreRange::where('store_id', $this->stores[1])->where('sku', 'BASE-3')->first();
        $this->assertSame(AssortmentStoreRange::SOURCE_LISTING, $row->source);
        $this->assertFalse($row->carried);
        $this->assertNotNull($this->gap('BASE-3', AssortmentGap::TYPE_ADD), 'not carried per the file → an add');
        $this->assertNull($this->gap('ADD-ME', AssortmentGap::TYPE_ADD), 'carried per the file → no add');

        $this->assertSame(2, app(AssortmentUploads::class)->clearRange($this->tenant->id));
    }

    public function test_the_must_stock_upload_and_guardrails_apply(): void
    {
        $this->seedEstate();
        $this->enter();
        $path = tempnam(sys_get_temp_dir(), 'ms') . '.csv';
        file_put_contents($path, "sku,store,reason\nSHIELD,,Private label\nNOT-A-SKU,,x\n");
        $r = app(AssortmentUploads::class)->importMustStock($this->tenant->id, $path);
        $this->assertSame(1, $r['added']);
        $this->assertTrue(AssortmentMustStock::where('tenant_id', $this->tenant->id)->where('sku', 'SHIELD')->whereNull('store_id')->exists());

        // At most one delist per category per store: DUD (bigger value) survives only if SHIELD is protected anyway.
        AssortmentMustStock::query()->delete();
        TenantAssortment::update($this->tenant, ['guardrails' => ['max_delists_per_category' => 1, 'delist_min_tier' => 'likely', 'carried_window_days' => 56]]);
        $run = app(AssortmentEngine::class)->run($this->tenant->fresh());

        $this->assertSame(1, AssortmentGap::where('tenant_id', $this->tenant->id)->where('type', AssortmentGap::TYPE_DELIST)->count());
        $this->assertSame(1, $run->stats['decisions']['capped_delists']);

        // Established-only delists: with 5 peers the tier is "likely", so none.
        TenantAssortment::update($this->tenant->fresh(), ['guardrails' => ['max_delists_per_category' => 3, 'delist_min_tier' => 'established', 'carried_window_days' => 56]]);
        app(AssortmentEngine::class)->run($this->tenant->fresh());
        $this->assertSame(0, AssortmentGap::where('tenant_id', $this->tenant->id)->where('type', AssortmentGap::TYPE_DELIST)->count());
    }

    public function test_the_setup_page_saves_guardrails_through_its_action(): void
    {
        $this->enter();

        \Livewire\Livewire::test(AssortmentSetup::class)
            ->callAction('guardrails', ['delist_min_tier' => 'established', 'carried_window_days' => 90, 'max_delists_per_category' => 2])
            ->assertHasNoActionErrors();

        $this->assertSame(['delist_min_tier' => 'established', 'carried_window_days' => 90, 'max_delists_per_category' => 2],
            TenantAssortment::guardrails($this->tenant->fresh()));
    }

    public function test_the_screens_actions_work_through_livewire(): void
    {
        $this->seedEstate();
        $admin = $this->enter();
        app(AssortmentEngine::class)->run($this->tenant);

        $add = $this->gap('ADD-ME', AssortmentGap::TYPE_ADD);
        \Livewire\Livewire::test(AssortmentValidation::class)->call('review', $add->id, AssortmentGap::VERDICT_SENSIBLE);
        $this->assertSame(AssortmentGap::VERDICT_SENSIBLE, $add->fresh()->review_verdict);

        $this->passGate($admin);

        \Livewire\Livewire::test(\App\Filament\Resources\AssortmentDecisionResource\Pages\ListAssortmentDecisions::class)
            ->assertCanSeeTableRecords([$add])
            ->callTableAction('accept', $add, ['assignee_id' => $admin->id, 'due_at' => now()->addDays(3)->toDateString(), 'note' => 'go'])
            ->assertHasNoTableActionErrors();
        $this->assertSame(AssortmentGap::STATUS_ACCEPTED, $add->fresh()->status);
        $this->assertSame($admin->id, $add->fresh()->assignee_id);

        \Livewire\Livewire::test(AssortmentDecision::class, ['decision' => $add->id])
            ->callAction('done', ['done_on' => today()->toDateString(), 'note' => 'listed'])
            ->assertHasNoActionErrors();
        $this->assertSame(AssortmentGap::TASK_DONE, $add->fresh()->task_status);
    }
}
