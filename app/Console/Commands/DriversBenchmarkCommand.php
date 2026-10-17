<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Ops\TenantOffboardingService;
use App\Support\Testing\DriverBenchmark;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Root Cause drivers — plant one known change per store (fewer shoppers, a
 * price rise, out-of-stock days, a promotion ending, a mix shift, a launch)
 * in a throwaway tenant and check the breakdown blames each on its driver.
 */
class DriversBenchmarkCommand extends Command
{
    protected $signature = 'drivers:benchmark {--seed=7} {--keep : Keep the synthetic tenant}';

    protected $description = 'Prove the sales driver breakdown attributes planted changes to the right driver';

    public function handle(): int
    {
        $tenant = Tenant::create(['name' => 'Drivers benchmark ' . now()->format('Y-m-d H:i'), 'slug' => 'drivers-bench-' . Str::lower(Str::random(8)),
            'status' => 'active', 'currency' => 'AED']);
        try {
            $r = app(DriverBenchmark::class)->run($tenant->id, (int) $this->option('seed'));
            $this->table(['Planted', 'Driver', 'Change to explain', 'Put on the driver', 'Attributed', 'Pass'],
                array_map(fn ($k, $v) => [$k, $v['driver'], number_format($v['change'], 2), number_format($v['explained'], 2),
                    round(100 * $v['attributed'], 1) . '%', $v['pass'] ? 'yes' : 'NO'], array_keys($r), $r));
            $ok = ! in_array(false, array_column($r, 'pass'), true);
            $this->line($ok ? 'All planted changes attributed (≥ ' . (100 * DriverBenchmark::PASS) . '%).' : 'Some planted changes were not attributed.');

            return $ok ? self::SUCCESS : self::FAILURE;
        } finally {
            if (! $this->option('keep')) {
                app(TenantOffboardingService::class)->eraseNow($tenant->id);
            }
        }
    }
}
