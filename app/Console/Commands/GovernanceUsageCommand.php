<?php

namespace App\Console\Commands;

use App\Platform\Governance\ContractEvaluator;
use App\Platform\Governance\MeteringService;
use Illuminate\Console\Command;

/**
 * P3.4 — a tenant's governance snapshot for a period: metered per-app usage (the
 * billable view) plus any open data-contract violations. Read-only.
 */
class GovernanceUsageCommand extends Command
{
    protected $signature = 'governance:usage {tenant : Tenant id} {--period=}';

    protected $description = "Show a tenant's per-app usage meters and open data-contract violations.";

    public function handle(MeteringService $metering, ContractEvaluator $contracts): int
    {
        $tenantId = (int) $this->argument('tenant');
        $period = $this->option('period') ?: now()->format('Y-m');

        $usage = $metering->tenantUsage($tenantId, $period);
        $this->info("Usage for tenant {$tenantId}, period {$period}:");
        if ($usage->isEmpty()) {
            $this->line('  (no metered usage)');
        } else {
            $this->table(['app', 'metric', 'count'],
                $usage->map(fn ($r) => [$r['app'], $r['metric'], $r['count']])->all());
        }

        $open = $contracts->open($tenantId);
        $this->line('');
        if ($open->isEmpty()) {
            $this->info('No open data-contract violations.');
        } else {
            $this->warn("{$open->count()} open data-contract violation(s):");
            $this->table(['feed', 'kind', 'detail'],
                $open->map(fn ($v) => [$v->feed_key, $v->kind, $v->detail])->all());
        }

        return self::SUCCESS;
    }
}
