<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Counts\CycleCountService;
use Illuminate\Console\Command;

/** W11 — refresh every store's cycle-count list from the live stock doubts (nightly, after detection). */
class GenerateCycleCountsCommand extends Command
{
    protected $signature = 'counts:generate {--tenant= : Only this tenant} {--per-store=25}';

    protected $description = 'Refresh the per-store cycle-count lists from live phantom / shrink / negative-stock findings';

    public function handle(CycleCountService $counts): int
    {
        $failures = 0;
        foreach (Tenant::query()->where('status', Tenant::STATUS_ACTIVE)
            ->when($this->option('tenant'), fn ($q, $t) => $q->whereKey((int) $t))->get() as $tenant) {
            try {
                $r = $counts->generate($tenant->id, max(1, (int) $this->option('per-store')));
                $this->line("  {$tenant->name}: +{$r['added']} listed, {$r['kept']} kept, {$r['cancelled']} cleared");
            } catch (\Throwable $e) {
                report($e);
                $this->error("  {$tenant->name}: {$e->getMessage()}");
                $failures++;
            }
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
