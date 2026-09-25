<?php

namespace App\Providers;

use App\Console\Commands\ExpireNoiseCommand;
use App\Console\Commands\PollSftpCommand;
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

        // P1.4 — the governed metric registry (apps register their metrics into it).
        $this->app->singleton(\App\Platform\Metrics\MetricRegistry::class);

        // P2.2 — the objective registry (apps register objectives + weights into it).
        $this->app->singleton(\App\Platform\Objectives\ObjectiveRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // WP2.4 (audit L3): audit changes to security-relevant configuration.
        foreach ([
            \App\Models\SsoConnection::class, \App\Models\ApiKey::class, \App\Models\ApiConnection::class,
            \App\Models\SftpConnection::class, \App\Models\TeamsConnection::class, \App\Models\OutboundTarget::class,
            \App\Models\TeamMember::class,
        ] as $audited) {
            $audited::observe(\App\Observers\ConfigChangeAuditor::class);
        }

        // 3b — org-wide strong password policy (applied wherever a password is
        // validated via Password::defaults(), e.g. the user form).
        \Illuminate\Validation\Rules\Password::defaults(
            fn () => \Illuminate\Validation\Rules\Password::min(10)->mixedCase()->numbers()
        );

        // P1.4 — register the Root-Cause app's governed metrics into the platform
        // metric registry. The platform owns the framework; the app owns its KPIs.
        $this->registerMetrics();

        // P2.2 — register the objectives + the Root-Cause rule→objective weights.
        $this->registerObjectives();

        // WP6.1: migrations never queue behind a long transaction holding a lock
        // on a hot table (and so never block every writer behind themselves):
        // any lock wait over database.migration_lock_timeout fails the deploy
        // instead. Index builds use App\Support\Database\ConcurrentIndex.
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\MigrationsStarted::class, function (): void {
            if (\Illuminate\Support\Facades\DB::getDriverName() === 'pgsql') {
                \Illuminate\Support\Facades\DB::statement("SET lock_timeout = '" . config('database.migration_lock_timeout', '10s') . "'");
            }
        });
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\MigrationsEnded::class, function (): void {
            if (\Illuminate\Support\Facades\DB::getDriverName() === 'pgsql') {
                \Illuminate\Support\Facades\DB::statement('RESET lock_timeout');
            }
        });

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
            \Illuminate\Console\Events\BackgroundScheduledTaskFinished::class,
            [\App\Listeners\RecordScheduledTaskRun::class, 'backgroundFinished'],
        );
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Auth\Events\Login::class,
            [\App\Listeners\RecordAuthActivity::class, 'login'],
        );
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Auth\Events\Failed::class,
            [\App\Listeners\RecordAuthActivity::class, 'failed'],
        );

        // Scheduling (WP5.2, audit H3). The nightly analytics no longer run as
        // separate commands at fixed UTC minutes for every tenant: nightly:dispatch
        // (hourly) starts each tenant's chain at its LOCAL night — aggregate →
        // profiles → baselines → detection → narrate → escalate → tidy → notify →
        // watches → data health → outcomes, each step after the previous one —
        // and its morning agents once that night has finished. See NightlyChain
        // and config/pipeline.php (deploy freeze window).
        //
        // Every entry is onOneServer() with a bounded withoutOverlapping(), and
        // the scheduler's mutexes live in the database cache store so they hold
        // across machines (the default file store is per machine).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->useCache(config('pipeline.lock_store', 'database'));

            // Hourly — start due tenant nights and mornings (needs the queue worker).
            $schedule->command(\App\Console\Commands\DispatchNightlyCommand::class)
                ->hourlyAt(5)
                ->onOneServer()
                ->withoutOverlapping(30);

            // Every 5 min — recover imports stuck in "importing" status for > 10 min
            $schedule->call(fn () => ImportProcessorService::recoverStuckImports(10))
                ->everyFiveMinutes()
                ->name('recover-stuck-imports')
                ->onOneServer()
                ->withoutOverlapping(10);

            // 00:30 UTC — return expired snoozes + expire due suppressions (all tenants;
            // time-based, so it doesn't depend on a tenant's night).
            $schedule->command(ExpireNoiseCommand::class)
                ->dailyAt('00:30')
                ->onOneServer()
                ->withoutOverlapping(60);

            // Hourly — poll SFTP connections for new flat files (M14)
            $schedule->command(PollSftpCommand::class)
                ->hourly()
                ->onOneServer()
                ->withoutOverlapping(55);

            // 04:30 UTC — data retention (2e), platform-wide.
            $schedule->command(\App\Console\Commands\PurgeOldDataCommand::class)
                ->dailyAt('04:30')
                ->onOneServer()
                ->withoutOverlapping(120);

            // WP6.6 — failed queue jobs kept a week; prunable models pruned.
            $schedule->command('queue:prune-failed', ['--hours' => (int) config('retention.failed_jobs_hours', 168)])
                ->dailyAt('04:10')
                ->onOneServer()
                ->withoutOverlapping(30);
            $schedule->command('model:prune')
                ->dailyAt('04:20')
                ->onOneServer()
                ->withoutOverlapping(30);

            // Hourly — health check: failed jobs, queue age, nightly chains (WP5.3).
            // It also pings HEARTBEAT_URL (an external cron monitor): if the
            // pings stop, the scheduler itself is down — a dead-man's switch.
            $heartbeat = (string) config('observability.heartbeat_url');
            $schedule->command(\App\Console\Commands\SystemHealthCheckCommand::class)
                ->hourlyAt(40)
                ->onOneServer()
                ->withoutOverlapping(30)
                ->pingOnSuccessIf($heartbeat !== '', $heartbeat);
        });
    }

    /**
     * P1.4 — register the Root-Cause app's KPIs into the platform metric registry.
     * The resolvers read from the app's own models; the platform governs the unit,
     * the version, and access — so every surface reads the same definition.
     */
    private function registerMetrics(): void
    {
        $registry = $this->app->make(\App\Platform\Metrics\MetricRegistry::class);
        $D = \App\Platform\Metrics\MetricDefinition::class;

        $make = fn (string $key, string $label, string $unit, string $desc, int $version, \Closure $resolver)
            => $registry->register(new $D($key, $label, $unit, $desc, $version, $resolver));

        $make('revenue_at_risk', 'Revenue at Risk', $D::UNIT_MONEY,
            'Sum of revenue at risk across open/in-progress investigations.', 1,
            fn (int $t) => (float) \App\Models\Investigation::where('tenant_id', $t)
                ->whereIn('status', ['open', 'in_progress'])->sum('revenue_at_risk'));

        $make('observed_recovery', 'Observed Recovery', $D::UNIT_MONEY,
            'Total observed recovery recorded across investigation outcomes.', 1,
            fn (int $t) => (float) \App\Models\InvestigationOutcome::where('tenant_id', $t)->sum('observed_recovery'));

        $make('recovery_rate', 'Recovery Rate', $D::UNIT_PERCENT,
            'Observed recovery as a percentage of revenue at risk (across outcomes).', 1,
            function (int $t) {
                $risk = (float) \App\Models\InvestigationOutcome::where('tenant_id', $t)->sum('revenue_at_risk');
                $rec  = (float) \App\Models\InvestigationOutcome::where('tenant_id', $t)->sum('observed_recovery');
                return $risk > 0 ? round($rec / $risk * 100, 1) : 0.0;
            });

        $make('open_investigations', 'Open Investigations', $D::UNIT_COUNT,
            'Investigations currently open or in progress.', 1,
            fn (int $t) => (float) \App\Models\Investigation::where('tenant_id', $t)
                ->whereIn('status', ['open', 'in_progress'])->count());

        $make('false_positive_rate', 'False Positive Rate', $D::UNIT_PERCENT,
            'Share of recorded outcomes flagged as false positives.', 1,
            function (int $t) {
                $total = \App\Models\InvestigationOutcome::where('tenant_id', $t)->count();
                $fp    = \App\Models\InvestigationOutcome::where('tenant_id', $t)->where('was_false_positive', true)->count();
                return $total > 0 ? round($fp / $total * 100, 1) : 0.0;
            });
    }

    /**
     * P2.2 — register the platform objectives and the Root-Cause rule→objective
     * weight map. Objectives are platform concepts; which rules matter for each is
     * the app's domain knowledge, so it lives here (not in the platform module).
     */
    private function registerObjectives(): void
    {
        $registry = $this->app->make(\App\Platform\Objectives\ObjectiveRegistry::class);
        $O = \App\Platform\Objectives\Objective::class;

        // General: uniform — no objective prioritised (default weight 1.0 everywhere).
        $registry->register(new $O('general', 'General', 'Balanced — no single objective prioritised.', 1.0), []);

        // Focused objectives use a low default (0.5) and boost the rules they care about.
        $registry->register(new $O('availability', 'On-Shelf Availability', 'Prioritise lost sales, stockouts and supply gaps.', 0.5), [
            'stockout_risk'         => 3.0,
            'safety_stock_breach'   => 3.0,
            'sales_drop'            => 2.0,
            'demand_forecast_break' => 2.0,
            'po_overdue'            => 2.0,
            'po_late_receipt'       => 2.0,
            'supplier_fill_rate'    => 2.0,
            'multi_location_imbalance' => 1.5,
        ]);

        $registry->register(new $O('margin', 'Margin', 'Prioritise price, cost and discount leakage.', 0.5), [
            'margin_erosion'             => 3.0,
            'price_anomaly'              => 3.0,
            'cost_spike'                 => 2.5,
            'discount_signal'            => 2.0,
            'revenue_concentration_risk' => 1.5,
        ]);

        $registry->register(new $O('waste', 'Waste & Shrink', 'Prioritise dead stock, shrink and returns.', 0.5), [
            'dead_stock'          => 3.0,
            'cumulative_shrink'   => 3.0,
            'inventory_shrinkage' => 3.0,
            'return_rate_spike'   => 2.5,
            'phantom_inventory'   => 2.0,
        ]);

        $registry->register(new $O('working_capital', 'Working Capital', 'Prioritise overstock and capital tied up in inventory.', 0.5), [
            'overstock'               => 3.0,
            'slow_moving_capital'     => 3.0,
            'dead_stock'              => 2.5,
            'phantom_inventory'       => 2.0,
            'reorder_point_staleness' => 1.5,
        ]);
    }
}
