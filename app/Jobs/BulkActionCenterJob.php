<?php

namespace App\Jobs;

use App\Models\Action;
use App\Models\AuditLog;
use App\Models\Investigation;
use App\Services\AuditLogger;
use App\Services\NotificationDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * BulkActionCenterJob — Feature 7 (Action Center bulk operations)
 *
 * Queued for large sets. Never bulk-completes (completion needs notes/outcome).
 * Handles partial failures and audits the operation.
 */
class BulkActionCenterJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<int>  $ids
     * @param  array<string,mixed>  $params
     */
    public function __construct(
        public int $tenantId,
        public int $userId,
        public string $action,
        public array $ids,
        public array $params = []
    ) {
    }

    public function handle(): void
    {
        $result = self::apply($this->tenantId, $this->userId, $this->action, $this->ids, $this->params);

        NotificationDispatcher::toUsers(
            [$this->userId],
            'Bulk action complete',
            "{$result['succeeded']} succeeded, {$result['failed']} failed ({$this->action}).",
            null,
            'heroicon-o-check-circle',
            $result['failed'] > 0 ? 'warning' : 'success'
        );
    }

    /**
     * @param  array<int>  $ids
     * @return array{succeeded:int,failed:int,failures:array<int,string>}
     */
    public static function apply(int $tenantId, int $userId, string $action, array $ids, array $params): array
    {
        $succeeded = 0;
        $failed    = 0;
        $failures  = [];

        // Tenant-scope via the parent investigation
        $actions = Action::whereIn('id', $ids)
            ->whereHas('investigation', fn ($q) => $q->where('tenant_id', $tenantId))
            ->with('investigation')
            ->get();

        foreach ($actions as $act) {
            try {
                self::applyOne($act, $userId, $action, $params);
                $succeeded++;
            } catch (\Throwable $e) {
                $failed++;
                $failures[$act->id] = $e->getMessage();
                Log::error("[ac-bulk:{$action}] action {$act->id}: " . $e->getMessage());
            }
        }

        $foundIds = $actions->pluck('id')->all();
        foreach (array_diff($ids, $foundIds) as $missing) {
            $failed++;
            $failures[$missing] = 'Not found in tenant scope';
        }

        if ($first = $actions->first()) {
            if ($first->investigation) {
                AuditLogger::log($first->investigation, AuditLog::EVENT_BULK_ACTION, "Bulk action {$action}: {$succeeded} ok, {$failed} failed", $userId);
            }
        }

        return ['succeeded' => $succeeded, 'failed' => $failed, 'failures' => $failures];
    }

    private static function applyOne(Action $act, int $userId, string $action, array $params): void
    {
        switch ($action) {
            case 'assign':
                $userIdTarget = (int) ($params['assigned_to'] ?? 0);
                // WP1.3: ids arrive from client-editable Livewire state — the
                // assignee must belong to the action's own tenant.
                if ($userIdTarget && ! \App\Models\User::whereKey($userIdTarget)->where('tenant_id', self::tenantOf($act))->exists()) {
                    throw new \RuntimeException('Assignee is not in this organisation');
                }
                $act->update([
                    'assigned_to' => $userIdTarget ?: null,
                    'status'      => $act->status === Action::STATUS_UNASSIGNED ? Action::STATUS_ASSIGNED : $act->status,
                ]);
                break;

            case 'reassign_team':
                $teamId = (int) ($params['team_id'] ?? 0);
                if ($teamId && ! \App\Models\Team::whereKey($teamId)->where('tenant_id', self::tenantOf($act))->exists()) {
                    throw new \RuntimeException('Team is not in this organisation');
                }
                $act->update(['assigned_team_id' => $teamId ?: null]);
                break;

            case 'change_priority':
                $priority = $params['priority'] ?? Action::PRIORITY_MEDIUM;
                if (! in_array($priority, [Action::PRIORITY_LOW, Action::PRIORITY_MEDIUM, Action::PRIORITY_HIGH, Action::PRIORITY_CRITICAL], true)) {
                    throw new \RuntimeException('Unknown priority');
                }
                $act->update(['priority' => $priority]);
                break;

            case 'escalate':
                if (! $act->isActive()) {
                    throw new \RuntimeException('Cannot escalate a completed/cancelled action');
                }
                $act->update(['escalation_state' => Action::ESCALATION_ESCALATED]);
                if ($act->investigation) {
                    AuditLogger::escalated($act->investigation, 'Bulk escalation', 'notify', $userId);
                }
                break;

            default:
                throw new \InvalidArgumentException("Unknown bulk action: {$action}");
        }
    }

    /** Actions carry no tenant_id — their tenant is their investigation's. */
    private static function tenantOf(Action $act): ?int
    {
        return $act->investigation?->tenant_id;
    }
}
