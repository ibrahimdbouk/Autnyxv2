<?php

namespace App\Console\Commands;

use App\Models\ApprovalQueueItem;
use App\Models\AutonomyPolicy;
use Illuminate\Console\Command;

/**
 * P4.8 — show a tenant's autonomy configuration and its pending approval queue.
 * Read-only.
 */
class AutonomyStatusCommand extends Command
{
    protected $signature = 'orchestration:autonomy {tenant : Tenant id}';

    protected $description = "Show a tenant's autonomy levels and pending approval queue.";

    public function handle(): int
    {
        $tenantId = (int) $this->argument('tenant');

        $policies = AutonomyPolicy::where('tenant_id', $tenantId)->orderBy('intent_type')->get();
        $this->info("Autonomy policies for tenant {$tenantId}:");
        if ($policies->isEmpty()) {
            $this->line('  (none — defaults to advise, min-confidence 0.8)');
        } else {
            $this->table(['intent_type', 'level', 'min_confidence', 'active'],
                $policies->map(fn ($p) => [$p->intent_type, $p->level, $p->min_confidence, $p->active ? 'yes' : 'no'])->all());
        }

        $pending = ApprovalQueueItem::where('tenant_id', $tenantId)
            ->where('status', ApprovalQueueItem::STATUS_PENDING)->count();
        $this->line('');
        $this->info("Pending approvals: {$pending}");

        return self::SUCCESS;
    }
}
