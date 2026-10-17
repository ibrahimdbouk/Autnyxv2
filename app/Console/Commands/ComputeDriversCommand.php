<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Drivers\DriverReports;
use Illuminate\Console\Command;

/**
 * Root Cause drivers — the weekly "what moved sales and why" for the business,
 * every store and every department (nightly, outcomes step; a breakdown is
 * only recomputed when its sales data changed).
 */
class ComputeDriversCommand extends Command
{
    protected $signature = 'drivers:compute {--tenant= : Only this tenant} {--force : Recompute even when the data has not changed}';

    protected $description = 'Compute the weekly sales driver breakdowns';

    public function handle(DriverReports $reports): int
    {
        $failed = 0;
        foreach (Tenant::query()->where('status', 'active')->when($this->option('tenant'), fn ($q, $t) => $q->whereKey((int) $t))->get() as $t) {
            try {
                $start = microtime(true);
                $r = $reports->computeWeekly((int) $t->id, (bool) $this->option('force'));
                $p = $r['periods'];
                $this->line("  {$t->name}: " . ($p ? "{$r['analyses']} breakdown(s), week {$p['from']}–{$p['to']} vs {$p['kind']}" : 'no sales yet')
                    . ' in ' . round(microtime(true) - $start, 1) . 's');
            } catch (\Throwable $e) {
                report($e);
                $failed++;
                $this->error("  {$t->name}: {$e->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
