<?php

namespace App\Providers;

use App\Console\Commands\ComputeBaselinesCommand;
use App\Console\Commands\DetectAnomaliesCommand;
use App\Console\Commands\EscalateInvestigationsCommand;
use App\Console\Commands\NarrateInvestigationsCommand;
use App\Console\Commands\NotifyAnomaliesCommand;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
        });
    }
}
