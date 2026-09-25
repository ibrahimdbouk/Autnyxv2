<?php

namespace App\Services\Pipeline;

use App\Jobs\Nightly\FinishNightlyRunJob;
use App\Jobs\Nightly\RunMorningAgentsJob;
use App\Jobs\Nightly\RunNightlyStepJob;
use App\Models\JobRun;
use App\Models\Tenant;
use App\Models\TenantNightlyRun;
use App\Services\Detection\TenantDetectionRunner;
use App\Services\Sales\SalesDailyAggregator;
use App\Support\Tenancy\TenantClock;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

/**
 * WP5.2 (audit H3, M1–M4, D3) — each tenant's night, in order, in its own
 * timezone, as one queued chain:
 *
 *   aggregate → profiles → baselines → detection (+ correlation) → narrate →
 *   escalate → tidy → notify → watches → data health → outcomes
 *
 * then the morning agents once the chain has finished. The old layout — every
 * command at a fixed UTC minute for all tenants, in the background, hoping the
 * previous one had finished — is gone: a step starts when the one before it
 * ends. A failed step is recorded (job_runs + the night's row) and the chain
 * carries on, except detection: if detection fails, the steps that read its
 * output don't run on stale data.
 */
class NightlyChain
{
    public const STEPS = ['aggregate', 'profiles', 'baselines', 'detection', 'narrate', 'escalate', 'tidy', 'notify', 'watches', 'health', 'outcomes'];

    /** Steps whose failure stops the rest of the night. */
    public const CRITICAL = ['detection'];

    /** Commands each step runs, with --tenant. */
    private const COMMANDS = [
        'profiles' => ['sku:profile', 'stores:profile', 'replenishment:compute', 'clusters:rebuild'],
        'baselines' => ['baselines:compute'],
        'narrate'  => ['investigations:narrate'],
        'escalate' => ['investigations:escalate'],
        'tidy'     => ['queue:tidy-tail'],
        'notify'   => ['anomalies:notify'],
        'watches'  => ['investigations:evaluate-watches'],
        'health'   => ['data:health'],
        'outcomes' => ['outcomes:measure'],
    ];

    /**
     * Start every tenant whose local night has come and not yet started, and
     * the morning agents of every tenant whose night has finished.
     *
     * @return array{nights:int, mornings:int}
     */
    public function dispatchDue(?Carbon $at = null, ?int $onlyTenant = null, bool $force = false): array
    {
        $at ??= Carbon::now();
        $nights = $mornings = 0;

        $tenants = Tenant::query()->where('status', 'active')
            ->when($onlyTenant, fn ($q) => $q->whereKey($onlyTenant))->get();

        foreach ($tenants as $tenant) {
            $local = $at->copy()->setTimezone(TenantClock::timezone($tenant->id));
            $hour  = (int) $local->format('G');
            $date  = $local->toDateString();
            $start = (int) config('pipeline.nightly_hour', 1);

            $inWindow = $hour >= $start && $hour < $start + max(1, (int) config('pipeline.catch_up_hours', 3));
            if ($force || $inWindow) {
                $run = $this->claimNight($tenant->id, $date, $force);
                if ($run) {
                    $this->dispatchChain($run);
                    $nights++;
                }
            }

            $morning = (int) config('pipeline.morning_hour', 6);
            if ($hour >= $morning && $hour < 12) {
                $run = TenantNightlyRun::where('tenant_id', $tenant->id)->whereDate('local_date', $date)->first();
                if ($run && $run->isFinished() && $run->agents_dispatched_at === null) {
                    $claimed = TenantNightlyRun::whereKey($run->id)->whereNull('agents_dispatched_at')
                        ->update(['agents_dispatched_at' => now()]);
                    if ($claimed) {
                        RunMorningAgentsJob::dispatch($tenant->id, $local->isMonday());
                        $mornings++;
                    }
                }
            }
        }

        return ['nights' => $nights, 'mornings' => $mornings];
    }

    /** Create the night's row once (unique per tenant + local date). */
    private function claimNight(int $tenantId, string $date, bool $force): ?TenantNightlyRun
    {
        $existing = TenantNightlyRun::where('tenant_id', $tenantId)->whereDate('local_date', $date)->first();
        if ($existing) {
            if (! $force) {
                return null; // this night already started (or ran) — never twice by itself
            }
            // --force: an operator re-runs the night (e.g. one killed by a deploy).
            $existing->update(['status' => TenantNightlyRun::STATUS_QUEUED, 'steps' => [], 'started_at' => null,
                'finished_at' => null, 'agents_dispatched_at' => null]);

            return $existing;
        }
        try {
            return TenantNightlyRun::create(['tenant_id' => $tenantId, 'local_date' => $date, 'status' => TenantNightlyRun::STATUS_QUEUED, 'steps' => []]);
        } catch (UniqueConstraintViolationException) {
            return null; // another dispatcher got there first
        }
    }

    public function dispatchChain(TenantNightlyRun $run): void
    {
        $runId = $run->id;
        $jobs = array_map(fn ($step) => new RunNightlyStepJob($runId, $step), self::STEPS);
        $jobs[] = new FinishNightlyRunJob($runId);

        Bus::chain($jobs)
            ->catch(function (\Throwable $e) use ($runId) {
                TenantNightlyRun::whereKey($runId)->update(['status' => TenantNightlyRun::STATUS_FAILED, 'finished_at' => now()]);
            })
            ->dispatch();
    }

    /** Run one step for one night. Throws only when a critical step fails. */
    public function runStep(TenantNightlyRun $run, string $step): void
    {
        $tenantId = (int) $run->tenant_id;
        if ($run->status === TenantNightlyRun::STATUS_QUEUED) {
            $run->update(['status' => TenantNightlyRun::STATUS_RUNNING, 'started_at' => now()]);
        }

        $t0 = microtime(true);
        $error = null;
        try {
            match ($step) {
                'aggregate' => $this->aggregate($tenantId),
                'detection' => $this->detect($tenantId, $run),
                default     => $this->commands($tenantId, self::COMMANDS[$step] ?? []),
            };
        } catch (\Throwable $e) {
            $error = $e;
            report($e);   // WP5.3: to Nightwatch
        }
        $ms = (int) round((microtime(true) - $t0) * 1000);

        $this->record($run, $step, $error ? JobRun::STATUS_FAILED : JobRun::STATUS_SUCCESS, $ms, $error?->getMessage());

        if ($error && in_array($step, self::CRITICAL, true)) {
            throw $error;
        }
    }

    public function finish(TenantNightlyRun $run): void
    {
        $failed = collect($run->fresh()->steps ?? [])->contains(fn ($s) => ($s['status'] ?? null) === JobRun::STATUS_FAILED);
        $run->update(['status' => $failed ? TenantNightlyRun::STATUS_FAILED : TenantNightlyRun::STATUS_DONE, 'finished_at' => now()]);
    }

    // ── Steps ────────────────────────────────────────────────────────────────

    /** Catch up the sales_daily aggregate for the last week of the tenant's days. */
    private function aggregate(int $tenantId): void
    {
        $today = TenantClock::today($tenantId);
        app(SalesDailyAggregator::class)->aggregateRange($tenantId, $today->copy()->subDays(7)->toDateString(), $today->toDateString());
    }

    private function detect(int $tenantId, TenantNightlyRun $run): void
    {
        $runner = app(TenantDetectionRunner::class);
        $wait   = (int) config('pipeline.lock_wait', 900);
        $mode   = config('detection.mode', 'full');

        if ($mode === 'incremental') {
            // Weekly full sweep on the tenant's Sunday night; otherwise per-key + aggregate.
            if (Carbon::parse($run->local_date)->isSunday()) {
                $runner->run($tenantId, 'full', $wait);

                return;
            }
            $runner->run($tenantId, 'incremental', $wait);
            $runner->run($tenantId, 'aggregate', $wait);

            return;
        }

        $runner->run($tenantId, 'full', $wait);
    }

    /** @param string[] $commands */
    private function commands(int $tenantId, array $commands): void
    {
        $failed = [];
        foreach ($commands as $command) {
            $code = Artisan::call($command, ['--tenant' => $tenantId]);
            if ($code !== 0) {
                $failed[] = "{$command} exited {$code}: " . Str::limit(trim(Artisan::output()), 200);
            }
        }
        if ($failed !== []) {
            throw new \RuntimeException(implode(' | ', $failed));
        }
    }

    private function record(TenantNightlyRun $run, string $step, string $status, int $ms, ?string $message): void
    {
        $steps = $run->fresh()->steps ?? [];
        $steps[$step] = ['status' => $status, 'ms' => $ms, 'message' => $message ? Str::limit($message, 300) : null];
        $run->update(['steps' => $steps]);

        try {
            JobRun::create([
                'tenant_id'   => $run->tenant_id,
                'command'     => 'nightly:' . $step,
                'status'      => $status,
                'duration_ms' => $ms,
                'message'     => $message ? Str::limit($message, 500) : null,
                'ran_at'      => now(),
            ]);
        } catch (\Throwable) {
            // observability is best-effort
        }
    }
}
