<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\Investigation;
use App\Services\AuditLogger;
use App\Services\Noise\SnoozeService;
use App\Services\NotificationDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * BulkInvestigationActionJob — Feature 7
 *
 * Applies a bulk action to a set of investigations. Queued for large sets so the
 * request never times out. Handles partial failures (valid records complete;
 * failures are collected) and audits the whole operation. The initiating user
 * gets an in-app summary when it finishes.
 */
class BulkInvestigationActionJob implements ShouldQueue
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

    public function handle(SnoozeService $snoozeService): void
    {
        $result = self::apply($this->tenantId, $this->userId, $this->action, $this->ids, $this->params, $snoozeService);

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
     * Apply the action synchronously. Returns a result summary.
     *
     * @param  array<int>  $ids
     * @return array{succeeded:int,failed:int,failures:array<int,string>}
     */
    public static function apply(int $tenantId, int $userId, string $action, array $ids, array $params, SnoozeService $snoozeService): array
    {
        $succeeded = 0;
        $failed    = 0;
        $failures  = [];

        $investigations = Investigation::where('tenant_id', $tenantId)
            ->whereIn('id', $ids)
            ->get();

        foreach ($investigations as $investigation) {
            try {
                self::applyOne($investigation, $userId, $action, $params, $snoozeService);
                $succeeded++;
            } catch (\Throwable $e) {
                $failed++;
                $failures[$investigation->id] = $e->getMessage();
                Log::error("[bulk:{$action}] investigation {$investigation->id}: " . $e->getMessage());
            }
        }

        // Report ids that did not resolve to a tenant record as failures.
        $foundIds = $investigations->pluck('id')->all();
        foreach (array_diff($ids, $foundIds) as $missing) {
            $failed++;
            $failures[$missing] = 'Not found in tenant scope';
        }

        if ($first = $investigations->first()) {
            AuditLogger::log(
                $first,
                AuditLog::EVENT_BULK_ACTION,
                "Bulk {$action}: {$succeeded} succeeded, {$failed} failed",
                $userId
            );
        }

        return ['succeeded' => $succeeded, 'failed' => $failed, 'failures' => $failures];
    }

    private static function applyOne(Investigation $investigation, int $userId, string $action, array $params, SnoozeService $snoozeService): void
    {
        switch ($action) {
            case 'assign_to_me':
                $investigation->update(['assigned_user_id' => $userId, 'assigned_at' => $investigation->assigned_at ?? now()]);
                AuditLogger::assigned($investigation, null, \App\Models\User::find($userId)?->name, $userId);
                break;

            case 'reassign_team':
                $teamId = (int) ($params['team_id'] ?? 0);
                $investigation->update(['assigned_team_id' => $teamId ?: null, 'assigned_at' => now()]);
                AuditLogger::assigned($investigation, \App\Models\Team::find($teamId)?->name, null, $userId);
                break;

            case 'change_priority':
                $priority = $params['priority'] ?? Investigation::PRIORITY_MEDIUM;
                $old = $investigation->priority;
                $investigation->update(['priority' => $priority]);
                AuditLogger::priorityChanged($investigation, $old, $priority, $userId);
                break;

            case 'snooze':
                $until = $snoozeService->resolveUntil($params['duration'] ?? '7d', $params['custom_date'] ?? null);
                $snoozeService->snooze($investigation, $until, $params['reason'] ?? 'known_issue', $params['notes'] ?? null, $userId);
                break;

            case 'dismiss':
                $old = $investigation->status;
                $investigation->update(['status' => Investigation::STATUS_CLOSED, 'closed_at' => now()]);
                AuditLogger::statusChanged($investigation, $old, Investigation::STATUS_CLOSED, $userId);
                break;

            default:
                throw new \InvalidArgumentException("Unknown bulk action: {$action}");
        }
    }
}
