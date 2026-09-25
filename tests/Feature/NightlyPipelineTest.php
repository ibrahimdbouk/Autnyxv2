<?php

namespace Tests\Feature;

use App\Jobs\Nightly\FinishNightlyRunJob;
use App\Jobs\Nightly\RunMorningAgentsJob;
use App\Jobs\Nightly\RunNightlyStepJob;
use App\Jobs\RunTenantDetectionJob;
use App\Models\DetectionDirtyKey;
use App\Models\JobRun;
use App\Models\Tenant;
use App\Models\TenantNightlyRun;
use App\Services\Anomaly\AnomalyDetectionService;
use App\Services\DataHealth\DataHealthService;
use App\Services\Detection\TenantDetectionRunner;
use App\Services\Pipeline\NightlyChain;
use App\Services\Pipeline\TenantDetectionLock;
use App\Support\Tenancy\TenantClock;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * WP5.2 (audit H3, M1–M4, D3) — each tenant's night starts at its local hour,
 * once, as an ordered chain; detection has one writer per tenant; commands and
 * scheduled tasks report failure truthfully; "today" is the tenant's today.
 */
class NightlyPipelineTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        TenantClock::forget();
        $this->tenant = $this->createTenant(['status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TenantClock::forget();
        parent::tearDown();
    }

    public function test_the_night_starts_at_one_am_local_once_and_in_order(): void
    {
        Bus::fake();
        $chain = app(NightlyChain::class);

        $this->assertSame(0, $chain->dispatchDue(Carbon::parse('2026-09-25 12:00', 'UTC'))['nights'], '16:00 in Dubai');

        $at = Carbon::parse('2026-09-25 21:05', 'UTC');   // 01:05 on the 26th in Dubai
        $this->assertSame(1, $chain->dispatchDue($at)['nights']);
        $this->assertSame(0, $chain->dispatchDue($at->copy()->addHour())['nights'], 'not twice');

        $run = TenantNightlyRun::sole();
        $this->assertSame('2026-09-26', $run->local_date->toDateString());
        Bus::assertChained(array_merge(
            array_fill(0, count(NightlyChain::STEPS), RunNightlyStepJob::class),
            [FinishNightlyRunJob::class],
        ));
    }

    public function test_a_tenant_elsewhere_runs_at_its_own_one_am(): void
    {
        Bus::fake();
        $this->tenant->forceFill(['timezone' => 'Europe/London'])->save();

        $this->assertSame(0, app(NightlyChain::class)->dispatchDue(Carbon::parse('2026-09-25 21:05', 'UTC'))['nights']);
        $this->assertSame(1, app(NightlyChain::class)->dispatchDue(Carbon::parse('2026-09-26 00:05', 'UTC'))['nights'], '01:05 BST');
    }

    public function test_the_chain_runs_every_step_and_records_them(): void
    {
        app(NightlyChain::class)->dispatchDue(Carbon::parse('2026-09-25 21:05', 'UTC'));   // sync queue: runs now

        $run = TenantNightlyRun::sole();
        $this->assertSame(TenantNightlyRun::STATUS_DONE, $run->status, json_encode($run->steps));
        $this->assertSame(NightlyChain::STEPS, array_keys($run->steps));
        $this->assertSame(count(NightlyChain::STEPS), JobRun::where('tenant_id', $this->tenant->id)->where('command', 'like', 'nightly:%')->count());
    }

    public function test_if_detection_cannot_run_the_steps_that_read_it_do_not(): void
    {
        config(['pipeline.lock_wait' => 1]);
        $lock = TenantDetectionLock::for($this->tenant->id);
        $lock->get();   // an import-triggered run is scanning

        try {
            app(NightlyChain::class)->dispatchDue(Carbon::parse('2026-09-25 21:05', 'UTC'));
        } catch (\Throwable) {
            // the sync queue rethrows the failed step
        } finally {
            $lock->release();
        }

        $run = TenantNightlyRun::sole();
        $this->assertSame(TenantNightlyRun::STATUS_FAILED, $run->status);
        $this->assertSame('failed', $run->steps['detection']['status']);
        $this->assertArrayNotHasKey('notify', $run->steps, 'no digest built on a night detection did not run');
    }

    public function test_the_morning_agents_start_once_after_the_night_finished(): void
    {
        Bus::fake();
        $run = TenantNightlyRun::create(['tenant_id' => $this->tenant->id, 'local_date' => '2026-09-26', 'status' => TenantNightlyRun::STATUS_RUNNING]);
        $morning = Carbon::parse('2026-09-26 02:10', 'UTC');   // 06:10 Dubai

        $this->assertSame(0, app(NightlyChain::class)->dispatchDue($morning)['mornings'], 'the night is still running');

        $run->update(['status' => TenantNightlyRun::STATUS_DONE]);
        $this->assertSame(1, app(NightlyChain::class)->dispatchDue($morning)['mornings']);
        $this->assertSame(0, app(NightlyChain::class)->dispatchDue($morning->copy()->addHour())['mornings']);
        Bus::assertDispatched(RunMorningAgentsJob::class, fn ($j) => $j->tenantId === $this->tenant->id);
    }

    public function test_an_import_triggered_run_waits_for_the_tenants_lock(): void
    {
        $lock = TenantDetectionLock::for($this->tenant->id);
        $lock->get();

        $job = (new RunTenantDetectionJob($this->tenant->id, 'incremental'))->withFakeQueueInteractions();
        $job->handle(app(TenantDetectionRunner::class));
        $lock->release();

        $job->assertReleased(300);
    }

    public function test_a_dirty_key_added_during_a_run_survives_it(): void
    {
        DetectionDirtyKey::create(['tenant_id' => $this->tenant->id, 'sku' => 'OLD', 'reason' => 'import', 'created_at' => now()]);
        $tenantId = $this->tenant->id;
        $this->app->instance(AnomalyDetectionService::class, new class($tenantId) extends AnomalyDetectionService {
            public function __construct(private int $t)
            {
                parent::__construct(app(\App\Services\Anomaly\BaselineCalculatorService::class));
            }

            public function runForTenant(int $tenantId, ?\App\Services\Detection\RunScope $scope = null, bool $aggregateOnly = false, bool $bucketed = false): void
            {
                DetectionDirtyKey::create(['tenant_id' => $this->t, 'sku' => 'NEW', 'reason' => 'import', 'created_at' => now()]);
            }
        });

        app(TenantDetectionRunner::class)->run($this->tenant->id, 'full');

        $this->assertSame(['NEW'], DetectionDirtyKey::where('tenant_id', $this->tenant->id)->pluck('sku')->all());
    }

    public function test_a_command_whose_tenant_failed_exits_with_failure_and_the_task_is_recorded_failed(): void
    {
        $this->mock(DataHealthService::class)->shouldReceive('computeForTenant')->andThrow(new \RuntimeException('boom'));
        $this->artisan('data:health', ['--tenant' => $this->tenant->id])->assertFailed();

        $task = app(Schedule::class)->command('data:health');
        $task->exitCode = 1;
        event(new ScheduledTaskFinished($task, 1.5));
        $this->assertSame(JobRun::STATUS_FAILED, JobRun::where('command', 'data:health')->latest('id')->value('status'));
    }

    public function test_today_is_the_tenants_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-25 22:30', 'UTC'));   // 02:30 on the 26th in Dubai

        [$from, $to] = TenantClock::dayRange($this->tenant->id);

        $this->assertSame('2026-09-25 20:00:00', $from->format('Y-m-d H:i:s'), 'midnight Dubai, in UTC');
        $this->assertSame('2026-09-26 19:59:59', $to->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-26', TenantClock::localDate($this->tenant->id));
    }
}
