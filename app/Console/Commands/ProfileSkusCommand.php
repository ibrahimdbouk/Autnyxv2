<?php

namespace App\Console\Commands;

use App\Models\SkuProfile;
use App\Models\Tenant;
use App\Services\Anomaly\SkuProfilerService;
use Illuminate\Console\Command;

class ProfileSkusCommand extends Command
{
    protected $signature = 'sku:profile {--tenant= : Profile a single tenant} {--window=90 : Lookback days}';

    protected $description = 'Classify each (SKU, store) by demand shape / volume / lifecycle into sku_profiles (best-fit layer)';

    public function handle(SkuProfilerService $profiler): int
    {
        $window  = (int) $this->option('window') ?: 90;
        $tenants = $this->option('tenant')
            ? Tenant::where('id', $this->option('tenant'))->get()
            : Tenant::all();

        foreach ($tenants as $tenant) {
            $count = $profiler->profileForTenant($tenant->id, $window);

            $dist = SkuProfile::where('tenant_id', $tenant->id)
                ->selectRaw('segment, COUNT(*) c')
                ->groupBy('segment')
                ->pluck('c', 'segment')
                ->toArray();

            $summary = collect($dist)->map(fn ($c, $s) => "{$s}={$c}")->implode(' ');
            $this->info("Tenant {$tenant->id}: {$count} profiles [{$summary}]");
        }

        return Command::SUCCESS;
    }
}
