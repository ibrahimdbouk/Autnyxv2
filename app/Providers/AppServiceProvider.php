<?php

namespace App\Providers;

use App\Console\Commands\ComputeBaselinesCommand;
use App\Console\Commands\ComputeDataHealthCommand;
use App\Console\Commands\DetectAnomaliesCommand;
use App\Console\Commands\EscalateInvestigationsCommand;
use App\Console\Commands\EvaluateWatchesCommand;
use App\Console\Commands\ExpireNoiseCommand;
use App\Console\Commands\MeasureOutcomesCommand;
use App\Console\Commands\NarrateInvestigationsCommand;
use App\Console\Commands\NotifyAnomaliesCommand;
use App\Console\Commands\PollSftpCommand;
use App\Console\Commands\ComputeReplenishmentCommand;
use App\Console\Commands\ProfileSkusCommand;
use App\Services\Import\ImportProcessorService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Ensure required storage subdirectories exist.
        // On Laravel Cloud the persistent storage volume is mounted over storage/ at
        // runtime, which wipes any subdirectories that were created during the build
        // step (framework/views, framework/cache, etc.).  Creating them here — in
        // register(), before any database or view code fires — guarantees they are
        // always present regardless of deploy order.
        foreach ([
            storage_path('framework/views'),
            storage_path('framework/cache/data'),
            storage_path('framework/sessions'),
            storage_path('logs'),
            storage_path('app/public'),
        ] as $dir) {
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
        }

        // Ensure the SQLite database file exists before any connection is attempted.
        // Laravel Cloud (and any containerised environment) starts with a clean
        // filesystem; the storage directory is a persistent volume, but the file
        // itself must be created on the very first boot. Using `register()` rather
        // than `boot()` runs this before any database-dependent code fires.
        if (config('database.default') === 'sqlite') {
            $path = config('database.connections.sqlite.database');
            if ($path && $path !== ':memory:' && ! file_exists($path)) {
                $dir = dirname($path);
                if (! is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }
                touch($path);
            }
        }

        // Super admins land on the /ops control plane after login (not a tenant
        // data dashboard). See App\Http\Responses\LoginResponse.
        $this->app->bind(
            \Filament\Auth\Http\Responses\Contracts\LoginResponse::class,
            \App\Http\Responses\LoginResponse::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 3b — org-wide strong password policy (applied wherever a password is
        // validated via Password::defaults(), e.g. the user form).
        \Illuminate\Validation\Rules\Password::defaults(
            fn () => \Illuminate\Validation\Rules\Password::min(10)->mixedCase()->numbers()
        );

        // Ops observability — record scheduled-task runs + auth activity.
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Console\Events\ScheduledTaskFinished::class,
            [\App\Listeners\RecordScheduledTaskRun::class, 'finished'],
        );
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Console\Events\ScheduledTaskFailed::class,
            [\App\Listeners\RecordScheduledTaskRun::class, 'failed'],
        );
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Auth\Events\Login::class,
            [\App\Listeners\RecordAuthActivity::class, 'login'],
        );
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Auth\Events\Failed::class,
            [\App\Listeners\RecordAuthActivity::class, 'failed'],
        );

        // Nightly automation pipeline (M9 + M10 + M11 + M15 corrected order)
        //
        // IMPORTANT: baselines must run BEFORE detection so that detection
        // consumes the latest z-score thresholds, not yesterday's.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // Every 5 min — recover imports stuck in "importing" status for > 10 min
            $schedule->call(fn () => ImportProcessorService::recoverStuckImports(10))
                ->everyFiveMinutes()
                ->name('recover-stuck-imports')
                ->withoutOverlapping();

            // 00:45 — Classify each (SKU, store) into sku_profiles (best-fit layer).
            //          Runs before baselines/detection so those can consult it.
            $schedule->command(ProfileSkusCommand::class)
                ->dailyAt('00:45')
                ->withoutOverlapping()
                ->runInBackground()
                ->appendOutputTo(storage_path('logs/sku-profile.log'));

            // 00:50 — Derive reorder points / safety stock / suggested order qty
            //          (B4) from the fresh profiles, before detection consumes them.
            $schedule->command(ComputeReplenishmentCommand::class)
                ->dailyAt('00:50')
                ->withoutOverlapping()
                ->runInBackground()
                ->appendOutputTo(storage_path('logs/replenishment.log'));

            // 01:00 — Recompute adaptive baselines (z-score thresholds) — MUST be first
            $schedule->command(ComputeBaselinesCommand::class)
                ->dailyAt('01:00')
                ->withoutOverlapping()
                ->runInBackground()
                ->appendOutputTo(storage_path('logs/baselines.log'));

            // 02:00 — Run all detection rules for every tenant (consumes fresh baselines)
            $schedule->command(DetectAnomaliesCommand::class)
                ->dailyAt('02:00')
                ->withoutOverlapping()
                ->runInBackground()
                ->appendOutputTo(storage_path('logs/anomaly-detect.log'));

            // 02:30 — Send digest emails for new anomalies
            $schedule->command(NotifyAnomaliesCommand::class)
                ->dailyAt('02:30')
                ->withoutOverlapping()
                ->runInBackground()
                ->appendOutputTo(storage_path('logs/anomaly-notify.log'));

            // 03:00 — Evaluate escalation rules against all open investigations
            $schedule->command(EscalateInvestigationsCommand::class)
                ->dailyAt('03:00')
                ->withoutOverlapping()
                ->runInBackground()
                ->appendOutputTo(storage_path('logs/escalation.log'));

            // 03:30 — Generate AI narratives for investigations with new/missing evidence
            $schedule->command(NarrateInvestigationsCommand::class)
                ->dailyAt('03:30')
                ->withoutOverlapping()
                ->runInBackground()
                ->appendOutputTo(storage_path('logs/narrate.log'));

            // ── M23 (Features 4–10) additions ──────────────────────────────

            // 00:30 — Return expired snoozes + expire due suppressions (Feature 6)
            $schedule->command(ExpireNoiseCommand::class)
                ->dailyAt('00:30')
                ->withoutOverlapping()
                ->runInBackground()
                ->appendOutputTo(storage_path('logs/noise-expire.log'));

            // 03:45 — Evaluate investigation watches (Feature 5) — after narrate
            $schedule->command(EvaluateWatchesCommand::class)
                ->dailyAt('03:45')
                ->withoutOverlapping()
                ->runInBackground()
                ->appendOutputTo(storage_path('logs/watch-eval.log'));

            // 04:00 — Recompute Data Health snapshots + critical alerts (Feature 4)
            $schedule->command(ComputeDataHealthCommand::class)
                ->dailyAt('04:00')
                ->withoutOverlapping()
                ->runInBackground()
                ->appendOutputTo(storage_path('logs/data-health.log'));

            // 04:15 — Measure post-action outcomes (Feature 8)
            $schedule->command(MeasureOutcomesCommand::class)
                ->dailyAt('04:15')
                ->withoutOverlapping()
                ->runInBackground()
                ->appendOutputTo(storage_path('logs/outcome-measure.log'));

            // Hourly — poll SFTP connections for new flat files (M14)
            $schedule->command(PollSftpCommand::class)
                ->hourly()
                ->withoutOverlapping()
                ->runInBackground()
                ->appendOutputTo(storage_path('logs/sftp-poll.log'));

            // 04:30 — Enforce data retention (2e). Runs after the full analytics
            // pipeline so nothing purges data the night's jobs still need.
            $schedule->command(\App\Console\Commands\PurgeOldDataCommand::class)
                ->dailyAt('04:30')
                ->withoutOverlapping()
                ->runInBackground()
                ->appendOutputTo(storage_path('logs/data-purge.log'));
        });
    }
}
