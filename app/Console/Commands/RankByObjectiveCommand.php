<?php

namespace App\Console\Commands;

use App\Models\Anomaly;
use App\Models\Tenant;
use App\Platform\Objectives\ObjectiveScorer;
use Illuminate\Console\Command;

/**
 * P2.2 — rank a tenant's anomalies for a chosen objective, demonstrating the
 * objective lens on real data: the same worklist re-orders as the objective
 * changes. Read-only.
 */
class RankByObjectiveCommand extends Command
{
    protected $signature = 'objectives:rank {--tenant= : Tenant id} {--objective= : general|availability|margin|waste|working_capital}';

    protected $description = "Rank a tenant's anomalies for a business objective.";

    public function handle(ObjectiveScorer $scorer): int
    {
        $tenantId = (int) $this->option('tenant');
        if (! $tenantId || ! Tenant::whereKey($tenantId)->exists()) {
            $this->error('Pass a valid --tenant=<id>.');
            return self::FAILURE;
        }

        $objective = $this->option('objective') ?: Tenant::find($tenantId)->activeObjective();

        $anomalies = Anomaly::where('tenant_id', $tenantId)
            ->latest('detected_at')
            ->limit(500)
            ->get();

        $ranked = $scorer->rank(
            $objective,
            $anomalies,
            fn (Anomaly $a) => (string) $a->rule_type,
            fn (Anomaly $a) => (float) ($a->context['revenue_impact'] ?? 0),
        );

        $this->info("Top anomalies for tenant {$tenantId} under objective '{$objective}':");
        foreach ($ranked->take(10) as $row) {
            /** @var Anomaly $a */
            $a = $row['item'];
            $this->line(sprintf('  %8.1f  %-26s %s', $row['score'], $a->rule_type, $a->sku ?? '—'));
        }

        return self::SUCCESS;
    }
}
