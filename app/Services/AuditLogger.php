<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Investigation;
use Illuminate\Support\Facades\Auth;

/**
 * AuditLogger — M18
 *
 * Central service for writing audit trail entries.
 * All investigation mutations should flow through here.
 */
class AuditLogger
{
    /**
     * Log a status change on an Investigation.
     */
    public static function statusChanged(
        Investigation $investigation,
        string $oldStatus,
        string $newStatus,
        ?int $userId = null
    ): void {
        self::write([
            'tenant_id'        => $investigation->tenant_id,
            'investigation_id' => $investigation->id,
            'user_id'          => $userId ?? Auth::id(),
            'event_type'       => AuditLog::EVENT_STATUS_CHANGED,
            'description'      => "Status changed from {$oldStatus} to {$newStatus}",
            'old_value'        => ['status' => $oldStatus],
            'new_value'        => ['status' => $newStatus],
        ]);
    }

    /**
     * Log an assignment / reassignment.
     */
    public static function assigned(
        Investigation $investigation,
        ?string $toTeamName = null,
        ?string $toUserName = null,
        ?int $userId = null
    ): void {
        $target = $toTeamName ?? $toUserName ?? 'unknown';
        self::write([
            'tenant_id'        => $investigation->tenant_id,
            'investigation_id' => $investigation->id,
            'user_id'          => $userId ?? Auth::id(),
            'event_type'       => AuditLog::EVENT_ASSIGNED,
            'description'      => "Assigned to {$target}",
            'new_value'        => array_filter([
                'team' => $toTeamName,
                'user' => $toUserName,
            ]),
        ]);
    }

    /**
     * Log a priority change.
     */
    public static function priorityChanged(
        Investigation $investigation,
        string $oldPriority,
        string $newPriority,
        ?int $userId = null
    ): void {
        self::write([
            'tenant_id'        => $investigation->tenant_id,
            'investigation_id' => $investigation->id,
            'user_id'          => $userId ?? Auth::id(),
            'event_type'       => AuditLog::EVENT_PRIORITY_CHANGED,
            'description'      => "Priority changed from {$oldPriority} to {$newPriority}",
            'old_value'        => ['priority' => $oldPriority],
            'new_value'        => ['priority' => $newPriority],
        ]);
    }

    /**
     * Log an action being created.
     */
    public static function actionCreated(
        Investigation $investigation,
        int $actionId,
        string $actionTitle,
        ?int $userId = null
    ): void {
        self::write([
            'tenant_id'        => $investigation->tenant_id,
            'investigation_id' => $investigation->id,
            'action_id'        => $actionId,
            'user_id'          => $userId ?? Auth::id(),
            'event_type'       => AuditLog::EVENT_ACTION_CREATED,
            'description'      => "Action created: {$actionTitle}",
            'new_value'        => ['action_id' => $actionId, 'title' => $actionTitle],
        ]);
    }

    /**
     * Log an action being completed.
     */
    public static function actionCompleted(
        Investigation $investigation,
        int $actionId,
        string $actionTitle,
        ?int $userId = null
    ): void {
        self::write([
            'tenant_id'        => $investigation->tenant_id,
            'investigation_id' => $investigation->id,
            'action_id'        => $actionId,
            'user_id'          => $userId ?? Auth::id(),
            'event_type'       => AuditLog::EVENT_ACTION_COMPLETED,
            'description'      => "Action completed: {$actionTitle}",
            'new_value'        => ['action_id' => $actionId, 'title' => $actionTitle],
        ]);
    }

    /**
     * Log an escalation event.
     */
    public static function escalated(
        Investigation $investigation,
        string $reason,
        string $action,
        ?int $userId = null
    ): void {
        self::write([
            'tenant_id'        => $investigation->tenant_id,
            'investigation_id' => $investigation->id,
            'user_id'          => $userId,  // null = system-triggered
            'event_type'       => AuditLog::EVENT_ESCALATED,
            'description'      => "Escalated: {$reason}",
            'new_value'        => ['action' => $action, 'reason' => $reason],
        ]);
    }

    /**
     * Log AI investigation being generated.
     */
    public static function aiGenerated(Investigation $investigation): void
    {
        self::write([
            'tenant_id'        => $investigation->tenant_id,
            'investigation_id' => $investigation->id,
            'user_id'          => null,  // system
            'event_type'       => AuditLog::EVENT_AI_GENERATED,
            'description'      => 'AI narrative generated',
        ]);
    }

    /**
     * Log a false-positive dismissal.
     */
    public static function fpDismissed(
        int $tenantId,
        ?int $investigationId,
        int $anomalyId,
        ?int $userId = null
    ): void {
        self::write([
            'tenant_id'        => $tenantId,
            'investigation_id' => $investigationId,
            'anomaly_id'       => $anomalyId,
            'user_id'          => $userId ?? Auth::id(),
            'event_type'       => AuditLog::EVENT_FP_DISMISSED,
            'description'      => "Anomaly #{$anomalyId} dismissed as false positive",
        ]);
    }

    /**
     * Log a comment being added, capturing its text and source (web | email)
     * so the audit log shows what was said and how it arrived.
     */
    public static function commentAdded(
        Investigation $investigation,
        ?int $userId,
        string $body,
        string $source = 'web',
        ?int $commentId = null
    ): void {
        $snippet = \Illuminate\Support\Str::limit(trim($body), 140);
        $via     = $source === 'email' ? ' (via email)' : '';

        self::write([
            'tenant_id'        => $investigation->tenant_id,
            'investigation_id' => $investigation->id,
            'user_id'          => $userId,
            'event_type'       => AuditLog::EVENT_COMMENT_ADDED,
            'description'      => 'Comment added' . $via . ': ' . $snippet,
            'new_value'        => ['source' => $source, 'comment_id' => $commentId, 'body' => $snippet],
        ]);
    }

    /**
     * Generic log entry — use when no specific method fits.
     */
    public static function log(
        Investigation $investigation,
        string $eventType,
        string $description,
        ?int $userId = null
    ): void {
        self::write([
            'tenant_id'        => $investigation->tenant_id,
            'investigation_id' => $investigation->id,
            'user_id'          => $userId ?? Auth::id(),
            'event_type'       => $eventType,
            'description'      => $description,
        ]);
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private static function write(array $attrs): void
    {
        try {
            AuditLog::create(array_merge(['created_at' => now()], $attrs));
        } catch (\Throwable $e) {
            // Audit logging is best-effort — never let it break the main flow
            \Illuminate\Support\Facades\Log::error('[AuditLogger] ' . $e->getMessage());
        }
    }
}
