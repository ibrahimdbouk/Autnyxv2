<?php

namespace App\Services\Watch;

use App\Models\AuditLog;
use App\Models\Investigation;
use App\Models\InvestigationWatch;
use App\Models\Team;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * WatchService — Feature 5 (subscription management)
 *
 * Start/stop watches for individuals or teams. Evaluation of meaningful events
 * lives in WatchEvaluationService.
 */
class WatchService
{
    /**
     * Start (or update) an individual watch on an investigation.
     */
    public function watchForUser(
        Investigation $investigation,
        int $userId,
        string $mode = InvestigationWatch::MODE_UNTIL_RESOLVED,
        ?Carbon $until = null,
        ?array $triggers = null
    ): InvestigationWatch {
        return $this->upsert($investigation, ['user_id' => $userId, 'team_id' => null], $mode, $until, $triggers, $userId);
    }

    /**
     * Start (or update) a team watch on an investigation.
     */
    public function watchForTeam(
        Investigation $investigation,
        int $teamId,
        string $mode = InvestigationWatch::MODE_UNTIL_RESOLVED,
        ?Carbon $until = null,
        ?array $triggers = null,
        ?int $createdBy = null
    ): InvestigationWatch {
        return $this->upsert($investigation, ['user_id' => null, 'team_id' => $teamId], $mode, $until, $triggers, $createdBy);
    }

    private function upsert(
        Investigation $investigation,
        array $subject,
        string $mode,
        ?Carbon $until,
        ?array $triggers,
        ?int $actorId
    ): InvestigationWatch {
        $watch = InvestigationWatch::updateOrCreate(
            [
                'investigation_id' => $investigation->id,
                'user_id'          => $subject['user_id'],
                'team_id'          => $subject['team_id'],
            ],
            [
                'tenant_id'         => $investigation->tenant_id,
                'mode'              => $mode,
                'watch_until'       => $mode === InvestigationWatch::MODE_UNTIL_DATE ? $until : null,
                'triggers'          => $triggers ?: InvestigationWatch::DEFAULT_TRIGGERS,
                'active'            => true,
                'ended_at'          => null,
                'ended_by'          => null,
                'created_by'        => $actorId,
                'last_state'        => WatchEvaluationService::snapshotState($investigation),
                'last_evaluated_at' => now(),
            ],
        );

        AuditLogger::log(
            $investigation,
            AuditLog::EVENT_WATCH_STARTED,
            'Watch started (' . $watch->getWatcherLabel() . ', ' . $mode . ')',
            $actorId
        );

        return $watch;
    }

    /**
     * Stop an individual's watch on an investigation.
     */
    public function unwatchForUser(Investigation $investigation, int $userId): void
    {
        $this->end(
            InvestigationWatch::where('investigation_id', $investigation->id)
                ->where('user_id', $userId)
                ->where('active', true)
                ->get(),
            $investigation,
            $userId
        );
    }

    public function unwatchForTeam(Investigation $investigation, int $teamId, ?int $actorId = null): void
    {
        $this->end(
            InvestigationWatch::where('investigation_id', $investigation->id)
                ->where('team_id', $teamId)
                ->where('active', true)
                ->get(),
            $investigation,
            $actorId
        );
    }

    /**
     * @param  Collection<int,InvestigationWatch>  $watches
     */
    private function end(Collection $watches, Investigation $investigation, ?int $actorId): void
    {
        foreach ($watches as $watch) {
            $watch->update([
                'active'   => false,
                'ended_at' => now(),
                'ended_by' => $actorId,
            ]);
            AuditLogger::log(
                $investigation,
                AuditLog::EVENT_WATCH_ENDED,
                'Watch ended (' . $watch->getWatcherLabel() . ')',
                $actorId
            );
        }
    }

    public function isWatchedByUser(Investigation $investigation, int $userId): bool
    {
        return InvestigationWatch::where('investigation_id', $investigation->id)
            ->where('user_id', $userId)
            ->where('active', true)
            ->exists();
    }

    /**
     * Active watches relevant to a user — their own individual watches plus any
     * of their teams' watches.
     *
     * @return Collection<int,InvestigationWatch>
     */
    public function watchesForUser(User $user, int $tenantId): Collection
    {
        $teamIds = Team::where('tenant_id', $tenantId)
            ->whereHas('members', fn ($q) => $q->where('users.id', $user->id))
            ->pluck('id');

        return InvestigationWatch::query()
            ->where('tenant_id', $tenantId)
            ->where('active', true)
            ->where(fn ($q) => $q
                ->where('user_id', $user->id)
                ->orWhereIn('team_id', $teamIds))
            ->with(['investigation.assignedTeam', 'team'])
            ->get();
    }
}
