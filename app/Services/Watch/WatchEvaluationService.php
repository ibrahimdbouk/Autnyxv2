<?php

namespace App\Services\Watch;

use App\Models\Action;
use App\Models\Investigation;
use App\Models\InvestigationWatch;
use App\Models\WatchNotification;
use App\Services\NotificationDispatcher;
use Illuminate\Support\Collection;

/**
 * WatchEvaluationService — Feature 5 (deterministic change detection)
 *
 * Runs nightly (after narration). For each active watch it snapshots the
 * investigation's meaningful state, diffs it against the stored snapshot, and
 * emits at most one notification per (watch, event_signature) — so the same
 * event is never sent twice, and tiny metric fluctuations are ignored.
 */
class WatchEvaluationService
{
    /** Material impact change must be at least this fraction to notify. */
    private const MATERIAL_IMPACT_PCT = 0.10;
    private const MATERIAL_IMPACT_MIN_ABS = 100.0;

    /**
     * Evaluate every active watch for a tenant. Returns the number of
     * notifications dispatched.
     */
    public function evaluateTenant(int $tenantId): int
    {
        $sent = 0;

        $watches = InvestigationWatch::where('tenant_id', $tenantId)
            ->where('active', true)
            ->with(['investigation', 'team'])
            ->get();

        foreach ($watches as $watch) {
            $sent += $this->evaluateWatch($watch);
        }

        return $sent;
    }

    public function evaluateWatch(InvestigationWatch $watch): int
    {
        $investigation = $watch->investigation;
        if (! $investigation) {
            $watch->update(['active' => false, 'ended_at' => now()]);
            return 0;
        }

        // Expire time-boxed watches
        if ($watch->mode === InvestigationWatch::MODE_UNTIL_DATE
            && $watch->watch_until
            && $watch->watch_until->isPast()) {
            $watch->update(['active' => false, 'ended_at' => now()]);
            return 0;
        }

        $current = self::snapshotState($investigation);
        $previous = $watch->last_state ?? [];

        $events = $this->diff($previous, $current, $investigation);

        $sent = 0;
        foreach ($events as $event) {
            if (! $watch->wantsTrigger($event['trigger'])) {
                continue;
            }

            // Dedup ledger — unique(watch_id, event_signature)
            $ledger = WatchNotification::firstOrNew([
                'watch_id'        => $watch->id,
                'event_signature' => $event['signature'],
            ]);
            if ($ledger->exists) {
                continue;
            }

            $ledger->fill([
                'tenant_id'        => $watch->tenant_id,
                'investigation_id' => $investigation->id,
                'event_type'       => $event['trigger'],
                'message'          => $event['message'],
                'sent_at'          => now(),
            ])->save();

            NotificationDispatcher::toUsers(
                $watch->recipientUserIds(),
                'Watched investigation update',
                $event['message'],
                url('/admin/' . ($investigation->tenant?->slug ?? '') . '/investigations/' . $investigation->id . '/investigate'),
                'heroicon-o-eye'
            );
            $sent++;
        }

        // Persist the new snapshot
        $watch->update([
            'last_state'        => $current,
            'last_evaluated_at' => now(),
        ]);

        // Auto-stop "until resolved" once resolved/closed (after resolution notice)
        if ($watch->mode === InvestigationWatch::MODE_UNTIL_RESOLVED
            && in_array($investigation->status, [Investigation::STATUS_RESOLVED, Investigation::STATUS_CLOSED], true)) {
            $watch->update(['active' => false, 'ended_at' => now()]);
        }

        return $sent;
    }

    /**
     * Deterministic snapshot of the meaningful state of an investigation.
     */
    public static function snapshotState(Investigation $investigation): array
    {
        $completedActions = $investigation->actions()->where('status', Action::STATUS_COMPLETED)->count();
        $totalActions     = $investigation->actions()->count();
        $overdueActions   = $investigation->actions()
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->whereNotIn('status', [Action::STATUS_COMPLETED, Action::STATUS_CANCELLED])
            ->count();
        $escalations = $investigation->escalationEvents()->count();

        return [
            'status'            => $investigation->status,
            'priority'          => $investigation->priority,
            'total_actions'     => $totalActions,
            'completed_actions' => $completedActions,
            'overdue_actions'   => $overdueActions,
            'escalations'       => $escalations,
            'revenue_at_risk'   => (float) ($investigation->revenue_at_risk ?? 0),
            'observed_recovery' => (float) ($investigation->observed_recovery ?? 0),
        ];
    }

    /**
     * Diff two snapshots into a list of meaningful events.
     *
     * @return array<int,array{trigger:string,signature:string,message:string}>
     */
    private function diff(array $prev, array $cur, Investigation $investigation): array
    {
        $events = [];
        $title  = '"' . \Illuminate\Support\Str::limit($investigation->title, 60) . '"';

        // Status change / resolution
        if (($prev['status'] ?? null) !== null && $prev['status'] !== $cur['status']) {
            $events[] = [
                'trigger'   => InvestigationWatch::TRIGGER_STATUS_CHANGE,
                'signature' => 'status_change:' . $prev['status'] . '->' . $cur['status'],
                'message'   => $title . ' status changed from ' . $prev['status'] . ' to ' . $cur['status'] . '.',
            ];

            if (in_array($cur['status'], [Investigation::STATUS_RESOLVED, Investigation::STATUS_CLOSED], true)) {
                $events[] = [
                    'trigger'   => InvestigationWatch::TRIGGER_RESOLUTION,
                    'signature' => 'resolution:' . $cur['status'],
                    'message'   => $title . ' was ' . $cur['status'] . '.',
                ];
            }
        }

        // Escalation
        if (($cur['escalations'] ?? 0) > ($prev['escalations'] ?? 0)) {
            $events[] = [
                'trigger'   => InvestigationWatch::TRIGGER_ESCALATION,
                'signature' => 'escalation:' . $cur['escalations'],
                'message'   => $title . ' was escalated.',
            ];
        }

        // Action taken (a new action was completed)
        if (($cur['completed_actions'] ?? 0) > ($prev['completed_actions'] ?? 0)) {
            $events[] = [
                'trigger'   => InvestigationWatch::TRIGGER_ACTION_TAKEN,
                'signature' => 'action_taken:' . $cur['completed_actions'],
                'message'   => $title . ' had an action completed.',
            ];
        }

        // Overdue (crossed into overdue)
        if (($cur['overdue_actions'] ?? 0) > 0 && ($prev['overdue_actions'] ?? 0) === 0) {
            $events[] = [
                'trigger'   => InvestigationWatch::TRIGGER_OVERDUE,
                'signature' => 'overdue:' . now()->toDateString(),
                'message'   => $title . ' has an overdue action.',
            ];
        }

        // Material impact change (>=10% and >= $100 absolute)
        $prevRisk = (float) ($prev['revenue_at_risk'] ?? 0);
        $curRisk  = (float) ($cur['revenue_at_risk'] ?? 0);
        if ($prevRisk > 0) {
            $absDelta = abs($curRisk - $prevRisk);
            if ($absDelta >= self::MATERIAL_IMPACT_MIN_ABS && ($absDelta / $prevRisk) >= self::MATERIAL_IMPACT_PCT) {
                $events[] = [
                    'trigger'   => InvestigationWatch::TRIGGER_MATERIAL_IMPACT_CHANGE,
                    'signature' => 'material:' . round($curRisk, 2),
                    'message'   => $title . ' revenue-at-risk changed to ' . number_format($curRisk, 2) . '.',
                ];
            }
        }

        // Recovery detected (observed recovery increased)
        if (($cur['observed_recovery'] ?? 0) > ($prev['observed_recovery'] ?? 0)) {
            $events[] = [
                'trigger'   => InvestigationWatch::TRIGGER_RECOVERY,
                'signature' => 'recovery:' . round((float) $cur['observed_recovery'], 2),
                'message'   => $title . ' recovery updated to ' . number_format((float) $cur['observed_recovery'], 2) . '.',
            ];
        }

        return $events;
    }

    /**
     * Best-effort "next expected event" hint for the My Watched view.
     */
    public static function nextExpectedEvent(Investigation $investigation): ?string
    {
        $nextDue = $investigation->actions()
            ->whereNotNull('due_at')
            ->whereNotIn('status', [Action::STATUS_COMPLETED, Action::STATUS_CANCELLED])
            ->orderBy('due_at')
            ->value('due_at');

        if ($nextDue) {
            return 'Action due ' . \Illuminate\Support\Carbon::parse($nextDue)->diffForHumans();
        }
        if (in_array($investigation->status, [Investigation::STATUS_OPEN, Investigation::STATUS_IN_PROGRESS], true)) {
            return 'Awaiting action / resolution';
        }
        return null;
    }
}
