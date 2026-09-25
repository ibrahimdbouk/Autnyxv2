<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Detection\TenantDetectionRunner;
use App\Services\Inventory\InventoryCurrentService;
use App\Services\Ops\TenantOffboardingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * WP6.3 (audit H31) — load test the nightly analytics on a synthetic tenant.
 *
 * Generates stores × SKUs × days of sales (sales_daily plus one receipt line
 * per store-SKU-day), weekly inventory snapshots with two lots, purchase
 * orders and returns — all with SQL generate_series, deterministic — then runs
 * the nightly chain's analytics (profiles, baselines, full v2 detection,
 * correlation) and reports time and peak memory per phase and per rule.
 * The synthetic tenant is erased afterwards unless --keep.
 *
 * Target scale is 300 stores × 20k SKUs × 90 days. Refuses to run in
 * production unless --allow-production (it writes millions of rows).
 */
class DetectionLoadTestCommand extends Command
{
    protected $signature = 'detection:load-test
        {--stores=20 : Stores}
        {--skus=2000 : SKUs}
        {--days=90 : Days of sales history}
        {--density=0.6 : Share of store-SKU-days with a sale}
        {--keep : Keep the synthetic tenant}
        {--allow-production : Run even in production}';

    protected $description = 'Seed a synthetic tenant at a given scale and time the nightly analytics per phase and per rule';

    /** @var array<int,array{0:string,1:string,2:string,3:string}> */
    private array $report = [];

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('allow-production')) {
            $this->error('Refusing to write a synthetic tenant into production (pass --allow-production to override).');

            return self::FAILURE;
        }

        $stores  = max(1, (int) $this->option('stores'));
        $skus    = max(1, (int) $this->option('skus'));
        $days    = max(14, (int) $this->option('days'));
        $density = min(1.0, max(0.05, (float) $this->option('density')));

        $tenant = Tenant::create([
            'name' => 'Load test ' . now()->format('Y-m-d H:i'), 'slug' => 'load-test-' . Str::lower(Str::random(8)),
            'status' => 'active', 'currency' => 'AED', 'settings' => ['detection_rules_v2' => true],
        ]);
        $this->info("Synthetic tenant {$tenant->id}: {$stores} stores × {$skus} SKUs × {$days} days (density {$density}).");

        try {
            $this->phase('seed', fn () => $this->seed($tenant->id, $stores, $skus, $days, $density));
            $counts = collect(['sales_daily', 'sales_transactions', 'inventory_levels', 'purchase_orders', 'sales_returns'])
                ->mapWithKeys(fn ($t) => [$t => DB::table($t)->where('tenant_id', $tenant->id)->count()]);
            foreach ($counts as $t => $n) {
                $this->line(sprintf('  %-20s %s rows', $t, number_format($n)));
            }

            $this->phase('inventory_current', fn () => app(InventoryCurrentService::class)->rebuild($tenant->id));
            foreach (['sku:profile', 'stores:profile', 'replenishment:compute', 'baselines:compute'] as $cmd) {
                $this->phase($cmd, fn () => Artisan::call($cmd, ['--tenant' => $tenant->id]));
            }

            $runner = app(TenantDetectionRunner::class);
            $this->phase('detection (full, v2) + correlation', fn () => $runner->run($tenant->id, 'full'));
            foreach ($runner->lastRuleStats() as $rule => $s) {
                $this->report[] = ['  rule: ' . $rule, number_format($s['ms'] / 1000, 2), number_format($s['peak_mb'], 1), (string) $s['flags'] . ($s['ok'] ? '' : ' FAILED')];
            }
            $this->line('');
            $this->line('Live anomalies: ' . DB::table('anomalies')->where('tenant_id', $tenant->id)->whereNull('dismissed_at')->where('lifecycle_state', '<>', 'resolved')->count()
                . ', investigations: ' . DB::table('investigations')->where('tenant_id', $tenant->id)->count());
        } finally {
            $this->table(['Phase', 'Seconds', 'Peak MB', 'Flags'], $this->report);
            if (! $this->option('keep')) {
                $this->phase('erase synthetic tenant', fn () => app(TenantOffboardingService::class)->eraseNow($tenant->id));
                $this->line('Erased. (' . end($this->report)[1] . 's)');
            } else {
                $this->warn("Kept tenant {$tenant->id}.");
            }
        }

        return self::SUCCESS;
    }

    private function phase(string $name, callable $fn): void
    {
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        $t0 = hrtime(true);
        $fn();
        $this->report[] = [$name, number_format((hrtime(true) - $t0) / 1e9, 2), number_format(memory_get_peak_usage(true) / 1048576, 1), ''];
        $this->line(sprintf('  %-40s %8.2fs', $name, (hrtime(true) - $t0) / 1e9));
    }

    private function seed(int $t, int $stores, int $skus, int $days, float $density): void
    {
        \App\Support\Testing\SyntheticRetailer::seed($t, $stores, $skus, $days, $density);
    }
}
