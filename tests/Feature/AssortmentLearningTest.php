<?php

namespace Tests\Feature;

use App\Filament\Pages\AssortmentLearning;
use App\Models\AssortmentCalibration;
use App\Models\AssortmentGap;
use App\Models\AssortmentPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Assortment\AssortmentEngine;
use App\Services\Assortment\CalibrationService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Concerns\SeedsAssortmentEstate;
use Tests\TestCase;

/**
 * v1.5 Phase 5 — learning: measured results → what they say per rule →
 * a versioned calibration proposed (never applied on its own) → approved by an
 * admin → the engine applies it from the next run, and every decision carries
 * its version → rejected or retired → the rules as built.
 */
class AssortmentLearningTest extends TestCase
{
    use SeedsAssortmentEstate;

    private User $admin;

    /** @var array<int,int> */
    private array $extra = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant(['apps' => [Tenant::APP_ROOT_CAUSE, Tenant::APP_ASSORTMENT], 'currency' => 'AED']);
        $this->admin = $this->actingAsTenantAdmin($this->tenant);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->tenant);
    }

    /** One measured decision, as the outcome measurer leaves it. */
    private function measured(string $type, int $i, array $evidence, float $actual, string $verdict, string $strength = 'measured'): void
    {
        $this->extra[$i] ??= DB::table('stores')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => "M{$i}", 'code' => "M{$i}",
            'format' => 'Supermarket', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('assortment_gaps')->insert([
            'tenant_id' => $this->tenant->id, 'store_id' => $this->extra[$i], 'sku' => strtoupper($type) . "-{$i}", 'type' => $type,
            'status' => AssortmentGap::STATUS_ACCEPTED, 'peer_group' => 'format:supermarket', 'value_low' => 0, 'value_mid' => 100, 'value_high' => 200,
            'confidence' => 0.6, 'confidence_tier' => 'likely', 'evidence' => json_encode($evidence), 'explanation' => json_encode([]),
            'as_of_date' => '2026-09-20', 'task_status' => AssortmentGap::TASK_DONE, 'done_at' => now()->subDays(60), 'measured_at' => now(),
            'measurement' => json_encode(['uplift_per_year' => $actual, 'expected_per_year' => $evidence['expected_sales_change_per_year'] ?? null,
                'strength' => $strength, 'verdict' => $verdict]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** 12 adds that each delivered half the estimate (outside the range, mostly not working) and 12 delists where more buyers switched than estimated. */
    private function overEstimated(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->measured('add', $i, ['gross_sales_per_year' => 2000, 'transfer' => ['share' => [0.3, 0.5, 0.7]], 'expected_sales_change_per_year' => 1000],
                500, $i <= 4 ? 'success' : 'failure');
            $this->measured('delist', $i, ['current_sales_per_year' => 10000, 'transfer' => ['share' => [0.4, 0.5, 0.6]], 'expected_sales_change_per_year' => -5000],
                -2000, 'success');
        }
    }

    public function test_the_measured_results_say_what_is_off_per_rule(): void
    {
        $this->overEstimated();
        $this->measured('add', 13, ['gross_sales_per_year' => 2000, 'expected_sales_change_per_year' => 1000], 5000, 'success', 'weak');

        $s = app(CalibrationService::class)->stats($this->tenant->id);
        $this->assertSame(13, $s['add']['n']);
        $this->assertSame(12, $s['add']['strong']);
        $this->assertSame(1, $s['add']['weak'], 'too few comparison stores: counted, not used');
        $this->assertEqualsWithDelta(0.5, $s['add']['ratio_median'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $s['add']['coverage'], 1e-9, '500 a year is below the 600–1,400 range');
        $this->assertEqualsWithDelta(4 / 12, $s['add']['success_rate'], 1e-4);
        $this->assertEqualsWithDelta(0.3, $s['delist']['share_bias'], 1e-9, '80% of buyers moved against 50% estimated');
    }

    public function test_a_proposal_is_made_once_with_reasons_and_never_applied_on_its_own(): void
    {
        $this->overEstimated();
        $svc = app(CalibrationService::class);

        $cal = $svc->propose($this->tenant);
        $this->assertSame(AssortmentCalibration::STATUS_PROPOSED, $cal->status);
        $this->assertSame(1, $cal->version);
        $this->assertSame(0.5, $cal->adjustments['value_scale']['add']);
        $this->assertSame(1.5, $cal->adjustments['range_widen']['add']);
        $this->assertSame(-1, $cal->adjustments['tier_shift']['add']);
        $this->assertSame(0.2, $cal->adjustments['share_shift'], 'shifted as far as the bound allows');
        $this->assertSame(1.5, $cal->adjustments['range_widen']['delist'], 'delists fell outside their range too');
        $this->assertCount(5, $cal->reasons);
        $this->assertStringContainsString('median 50% of the estimate', implode(' ', $cal->reasons));
        $this->assertNull(CalibrationService::active($this->tenant->id), 'proposed is not applied');

        $this->assertSame($cal->id, $svc->propose($this->tenant)->id, 'the same proposal is not made twice');
        $this->assertSame(1, AssortmentCalibration::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_too_few_measured_results_propose_nothing(): void
    {
        for ($i = 1; $i <= CalibrationService::MIN_MEASURED - 1; $i++) {
            $this->measured('add', $i, ['gross_sales_per_year' => 2000, 'transfer' => ['share' => [0.3, 0.5, 0.7]], 'expected_sales_change_per_year' => 1000], 100, 'failure');
        }
        $this->assertNull(app(CalibrationService::class)->propose($this->tenant));
    }

    public function test_an_approved_calibration_changes_the_next_run_and_is_recorded(): void
    {
        $this->seedEstate();
        app(AssortmentEngine::class)->run($this->tenant);
        $add = fn () => AssortmentGap::where('tenant_id', $this->tenant->id)->where('sku', 'ADD-ME')->first();
        $delist = fn () => AssortmentGap::where('tenant_id', $this->tenant->id)->where('sku', 'DUD')->where('type', 'delist')->first();
        $before = $add();
        $delistBefore = $delist();
        $this->assertArrayNotHasKey('calibration', $before->evidence);

        $cal = AssortmentCalibration::create(['tenant_id' => $this->tenant->id, 'version' => 1, 'status' => AssortmentCalibration::STATUS_PROPOSED,
            'adjustments' => array_replace_recursive(CalibrationService::identity(), ['value_scale' => ['add' => 0.5], 'tier_shift' => ['add' => -1], 'share_shift' => 0.1]),
            'previous' => CalibrationService::identity(), 'reasons' => ['test'], 'basis' => []]);
        app(CalibrationService::class)->approve($cal, $this->admin, 'Tested with the category team');

        $this->travel(1)->minutes();
        app(AssortmentEngine::class)->run($this->tenant);
        $after = $add();
        // Both apply: the estimate × 0.5, and 10 points more of its sales taken from the shelf (net = gross × (1 − share)).
        $share = (float) $before->evidence['transfer']['share'][1];
        $factor = 0.5 * (1 - min(0.95, $share + 0.1)) / (1 - $share);
        $this->assertEqualsWithDelta($before->value_mid * $factor, $after->value_mid, 0.02 * $before->value_mid);
        $this->assertEqualsWithDelta($before->evidence['expected_sales_change_per_year'] * $factor, $after->evidence['expected_sales_change_per_year'], 1.0);
        $this->assertEqualsWithDelta($before->evidence['gross_sales_per_year'] * 0.5, $after->evidence['gross_sales_per_year'], 1.0, 'the estimate itself, halved');
        $this->assertSame(1, $after->evidence['calibration']['version']);
        $this->assertSame(['established' => 'likely', 'likely' => 'speculative'][$before->confidence_tier] ?? 'speculative', $after->confidence_tier);
        $this->assertStringContainsString('Calibration cal-1', json_encode($after->explanation));
        $this->assertEqualsWithDelta($delistBefore->evidence['transfer']['share'][1] + 0.1, $delist()->evidence['transfer']['share'][1], 1e-3,
            'where buyers go, shifted');
        $this->assertSame(1, AssortmentPlan::where('tenant_id', $this->tenant->id)->first()->constraints['calibration']);

        // Retired: the next run is as built again.
        app(CalibrationService::class)->retire($this->tenant, $this->admin);
        $this->travel(1)->minutes();
        app(AssortmentEngine::class)->run($this->tenant);
        $this->assertEqualsWithDelta($before->value_mid, $add()->value_mid, 0.01);
        $this->assertArrayNotHasKey('calibration', $add()->evidence);
    }

    public function test_approving_replaces_the_one_in_force_and_rejecting_changes_nothing(): void
    {
        $this->overEstimated();
        $svc = app(CalibrationService::class);
        $one = $svc->propose($this->tenant);
        $svc->approve($one, $this->admin);
        $this->assertSame(1, CalibrationService::active($this->tenant->id)['version']);

        // New results: adds now deliver what is estimated before calibration → back towards × 1.
        DB::table('assortment_gaps')->where('tenant_id', $this->tenant->id)->where('type', 'add')
            ->update(['measurement' => json_encode(['uplift_per_year' => 1000, 'expected_per_year' => 1000, 'strength' => 'measured', 'verdict' => 'success'])]);
        $two = $svc->propose($this->tenant);
        $this->assertSame(2, $two->version);
        $this->assertEquals(1.0, $two->adjustments['value_scale']['add']);
        $this->assertSame(0, $two->adjustments['tier_shift']['add'], 'adds work again: confidence back as built');

        $svc->reject($two, $this->admin, 'Wait for another quarter');
        $this->assertSame(1, CalibrationService::active($this->tenant->id)['version'], 'rejecting changes nothing');
        $this->assertSame(AssortmentCalibration::STATUS_REJECTED, $two->fresh()->status);
    }

    public function test_the_nightly_measure_step_proposes_and_tells_the_admins(): void
    {
        $this->overEstimated();
        Artisan::call('assortment:measure', ['--tenant' => $this->tenant->id]);

        $this->assertSame(1, AssortmentCalibration::where('tenant_id', $this->tenant->id)->where('status', 'proposed')->count());
        $titles = DB::table('notifications')->where('notifiable_id', $this->admin->id)->pluck('data')->map(fn ($d) => json_decode($d, true)['title'] ?? '');
        $this->assertTrue($titles->contains('Assortment calibration cal-1 to review'));
    }

    public function test_the_learning_page_shows_results_and_approves_for_admins_only(): void
    {
        $this->overEstimated();
        $cal = app(CalibrationService::class)->propose($this->tenant);

        $this->get(AssortmentLearning::getUrl(tenant: $this->tenant))->assertOk()
            ->assertSee('What the measured results say')->assertSee('Proposal cal-1')->assertSee('No calibration: the rules run as built')
            ->assertSee('Enough to tune');

        Livewire::test(AssortmentLearning::class)
            ->callAction('approve', ['note' => 'Agreed'], ['id' => $cal->id])->assertHasNoActionErrors();
        $this->assertSame(AssortmentCalibration::STATUS_APPROVED, $cal->fresh()->status);
        $this->get(AssortmentLearning::getUrl(tenant: $this->tenant))->assertSee('cal-1')->assertSee('Adds: estimates × 0.50');

        Livewire::test(AssortmentLearning::class)->callAction('retire')->assertHasNoActionErrors();
        $this->assertNull(CalibrationService::active($this->tenant->id));

        $this->actingAs($this->createUser($this->tenant));
        $this->get(AssortmentLearning::getUrl(tenant: $this->tenant))->assertForbidden();
    }
}
