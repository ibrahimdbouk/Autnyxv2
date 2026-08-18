<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\EscalationEvent;
use App\Models\EscalationRule;
use App\Models\Investigation;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * EscalationService — M18
 *
 * Evaluates all active EscalationRules against open Investigations and
 * fires escalation actions when trigger conditions are met.
 *
 * Called nightly by EscalateInvestigationsCommand at 03:00.
 * Can also be called on demand via `php artisan investigations:escalate`.
 */
class EscalationService
{
    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Run escalation for all tenants.
     */
    public function runForAllTenants(): void
    {
        Tenant::all()->each(fn ($t) => $this->runForTenant($t->id));
    }

    /**
     * Run escalation for one tenant.
     * Evaluates each active rule against all open investigations.
     */
    public function runForTenant(int $tenantId): void
    {
        $rules = EscalationRule::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        if ($rules->isEmpty()) return;

        $investigations = Investigation::where('tenant_id', $tenantId)
            ->whereIn('status', [Investigation::STATUS_OPEN, Investigation::STATUS_IN_PROGRESS])
            ->get();

        $fired = 0;
        foreach ($investigations as $investigation) {
            foreach ($rules as $rule) {
                if ($this->shouldFire($rule, $investigation)) {
                    $this->fire($rule, $investigation);
                    $fired++;
                    break; // one rule per investigation per run to avoid cascade
                }
            }
        }

        Log::info("[M18/escalation] Tenant {$tenantId}: {$fired} escalation(s) fired");
    }

    // =========================================================================
    // PRIVATE — TRIGGER EVALUATION
    // =========================================================================

    private function shouldFire(EscalationRule $rule, Investigation $investigation): bool
    {
        // Priority filter
        if (!$rule->appliesToPriority($investigation->priority)) return false;

        // Already escalated by this rule in the last 24 hours (prevent re-firing)
        $recentFire = EscalationEvent::where('investigation_id', $investigation->id)
            ->where('escalation_rule_id', $rule->id)
            ->where('triggered_at', '>=', Carbon::now()->subHours(24))
            ->exists();

        if ($recentFire) return false;

        return match ($rule->trigger_type) {
            EscalationRule::TRIGGER_TIME_OPEN =>
                $this->checkTimeOpen($rule, $investigation),

            EscalationRule::TRIGGER_UNASSIGNED =>
                $this->checkUnassigned($rule, $investigation),

            EscalationRule::TRIGGER_NO_ACTION =>
                $this->checkNoAction($rule, $investigation),

            EscalationRule::TRIGGER_PRIORITY_THRESHOLD =>
                $this->checkPriorityThreshold($rule, $investigation),

            default => false,
        };
    }

    /** Open for more than N hours */
    private function checkTimeOpen(EscalationRule $rule, Investigation $investigation): bool
    {
        $hours = (int) ($rule->trigger_value ?? 48);
        return $investigation->opened_at
            && $investigation->opened_at->diffInHours(now()) >= $hours;
    }

    /** Open for more than N hours with no team assigned */
    private function checkUnassigned(EscalationRule $rule, Investigation $investigation): bool
    {
        if ($investigation->assigned_team_id) return false;
        $hours = (int) ($rule->trigger_value ?? 24);
        return $investigation->opened_at
            && $investigation->opened_at->diffInHours(now()) >= $hours;
    }

    /** In progress for more than N hours with no completed action */
    private function checkNoAction(EscalationRule $rule, Investigation $investigation): bool
    {
        if ($investigation->status !== Investigation::STATUS_IN_PROGRESS) return false;

        $hasCompletedAction = $investigation->actions()
            ->where('status', \App\Models\Action::STATUS_COMPLETED)
            ->exists();

        if ($hasCompletedAction) return false;

        $hours = (int) ($rule->trigger_value ?? 48);
        $since = $investigation->assigned_at ?? $investigation->opened_at;
        return $since && $since->diffInHours(now()) >= $hours;
    }

    /** Investigation priority is at or above the rule's threshold */
    private function checkPriorityThreshold(EscalationRule $rule, Investigation $investigation): bool
    {
        $order = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];
        $threshold = $rule->trigger_value ?? 'high';
        return ($order[$investigation->priority] ?? 0) >= ($order[$threshold] ?? 0);
    }

    // =========================================================================
    // PRIVATE — FIRE
    // =========================================================================

    private function fire(EscalationRule $rule, Investigation $investigation): void
    {
        $fromPriority = $investigation->priority;

        match ($rule->escalation_action) {
            EscalationRule::ACTION_REASSIGN_TEAM =>
                $this->reassignTeam($rule, $investigation),

            EscalationRule::ACTION_REASSIGN_USER =>
                $this->reassignUser($rule, $investigation),

            EscalationRule::ACTION_ELEVATE_PRIORITY =>
                $this->elevatePriority($investigation),

            EscalationRule::ACTION_NOTIFY_LEAD =>
                $this->notifyLead($rule, $investigation),

            default => null,
        };

        // Record the escalation event
        EscalationEvent::create([
            'investigation_id'   => $investigation->id,
            'escalation_rule_id' => $rule->id,
            'trigger_reason'     => $this->buildTriggerReason($rule, $investigation),
            'escalation_action'  => $rule->escalation_action,
            'triggered_at'       => now(),
            'to_team_id'         => $rule->target_team_id,
            'to_user_id'         => $rule->target_user_id,
            'from_priority'      => $fromPriority,
            'to_priority'        => $investigation->fresh()->priority,
        ]);

        AuditLogger::escalated(
            $investigation,
            $this->buildTriggerReason($rule, $investigation),
            $rule->escalation_action
        );

        Log::info("[M18/escalation] Rule [{$rule->name}] fired on investigation #{$investigation->id}");
    }

    private function reassignTeam(EscalationRule $rule, Investigation $investigation): void
    {
        if (!$rule->target_team_id) return;
        $investigation->update([
            'assigned_team_id' => $rule->target_team_id,
            'assigned_at'      => $investigation->assigned_at ?? now(),
        ]);
    }

    private function reassignUser(EscalationRule $rule, Investigation $investigation): void
    {
        if (!$rule->target_user_id) return;
        $investigation->update([
            'assigned_user_id' => $rule->target_user_id,
            'assigned_at'      => $investigation->assigned_at ?? now(),
        ]);
    }

    private function elevatePriority(Investigation $investigation): void
    {
        $order    = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];
        $reverse  = [1 => 'low', 2 => 'medium', 3 => 'high', 4 => 'critical'];
        $current  = $order[$investigation->priority] ?? 2;
        $elevated = $reverse[min(4, $current + 1)];

        if ($elevated !== $investigation->priority) {
            AuditLogger::priorityChanged($investigation, $investigation->priority, $elevated);
            $investigation->update(['priority' => $elevated]);
        }
    }

    private function notifyLead(EscalationRule $rule, Investigation $investigation): void
    {
        // Notification delivery is handled by M10 mail infrastructure.
        // For now, log — full email hook can be added when mail is configured.
        Log::notice("[M18/escalation] NOTIFY LEAD: investigation #{$investigation->id} — {$investigation->title}");
    }

    private function buildTriggerReason(EscalationRule $rule, Investigation $investigation): string
    {
        return match ($rule->trigger_type) {
            EscalationRule::TRIGGER_TIME_OPEN =>
                "Open for {$investigation->opened_at?->diffForHumans()} with no resolution",
            EscalationRule::TRIGGER_UNASSIGNED =>
                "Unassigned for {$investigation->opened_at?->diffForHumans()}",
            EscalationRule::TRIGGER_NO_ACTION =>
                "In progress with no completed action for {$investigation->assigned_at?->diffForHumans()}",
            EscalationRule::TRIGGER_PRIORITY_THRESHOLD =>
                "Priority reached {$investigation->priority}",
            default => $rule->name,
        };
    }
}
