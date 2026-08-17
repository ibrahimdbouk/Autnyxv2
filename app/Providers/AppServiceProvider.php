<?php

namespace App\Providers;

use App\Console\Commands\ComputeBaselinesCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
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
        // Schedule nightly baseline computation (M9 adaptive thresholds)
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command(ComputeBaselinesCommand::class)
                ->dailyAt('02:00')
                ->withoutOverlapping()
                ->runInBackground()
                ->appendOutputTo(storage_path('logs/baselines.log'));
        });
    }
}
