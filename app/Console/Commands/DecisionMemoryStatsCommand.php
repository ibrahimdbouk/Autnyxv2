<?php

namespace App\Console\Commands;

use App\Models\DecisionCase;
use Illuminate\Console\Command;

/**
 * P4.3 — a tenant's decision-memory summary: how many cases per intent type, and
 * how they resolved. Read-only. The case base is empty until real recommendations
 * flow through it (a later consumer step).
 */
class DecisionMemoryStatsCommand extends Command
{
    protected $signature = 'memory:stats {tenant : Tenant id}';

    protected $description = "Summarise a tenant's decision-memory case base.";

    public function handle(): int
    {
        $tenantId = (int) $this->argument('tenant');

        $rows = DecisionCase::query()
            ->where('tenant_id', $tenantId)
            ->selectRaw('intent_type, outcome_status, count(*) as n')
            ->groupBy('intent_type', 'outcome_status')
            ->orderBy('intent_type')
            ->get();

        if ($rows->isEmpty()) {
            $this->info("No decision cases recorded for tenant {$tenantId} yet.");
            return self::SUCCESS;
        }

        $this->table(['intent_type', 'outcome', 'count'],
            $rows->map(fn ($r) => [$r->intent_type, $r->outcome_status, $r->n])->all());

        $resolved = DecisionCase::query()->where('tenant_id', $tenantId)
            ->whereIn('outcome_status', DecisionCase::RESOLVED)->count();
        $success = DecisionCase::query()->where('tenant_id', $tenantId)
            ->where('outcome_status', DecisionCase::OUTCOME_SUCCESS)->count();

        $rate = $resolved > 0 ? round($success / $resolved * 100, 1) : 0.0;
        $this->info("Resolved cases: {$resolved}; overall success rate: {$rate}%.");

        return self::SUCCESS;
    }
}
