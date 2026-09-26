<?php

namespace App\Observers;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * WP2.4 (audit L3) — record who changed a security-relevant configuration and
 * WHICH fields changed (never the values): SSO, API keys, integration
 * credentials, Teams / SFTP / outbound connections, team membership.
 */
class ConfigChangeAuditor
{
    private const IGNORED = ['updated_at', 'created_at', 'last_used_at', 'last_polled_at', 'last_success_at',
        'last_error', 'last_error_at', 'status', 'last_run_at', 'last_synced_at',
        'last_finding_id', 'failure_streak', 'last_failure_at'];   // W13: webhook bookkeeping

    public function created(Model $model): void
    {
        $this->write($model, 'created', []);
    }

    public function updated(Model $model): void
    {
        $fields = array_values(array_diff(array_keys($model->getChanges()), self::IGNORED));
        if ($fields !== []) {
            $this->write($model, 'updated', $fields);
        }
    }

    public function deleted(Model $model): void
    {
        $this->write($model, 'deleted', []);
    }

    private function write(Model $model, string $verb, array $fields): void
    {
        $tenantId = $model->getAttribute('tenant_id')
            ?? (method_exists($model, 'team') ? $model->team?->tenant_id : null);
        if (! $tenantId) {
            return;
        }

        try {
            AuditLog::create([
                'tenant_id'   => $tenantId,
                'user_id'     => auth()->id(),
                'event_type'  => 'config_changed',
                'description' => class_basename($model) . ' #' . $model->getKey() . ' ' . $verb
                    . ($fields ? ' (' . implode(', ', $fields) . ')' : ''),
            ]);
        } catch (Throwable) {
            // best-effort — never block the change on the audit write.
        }
    }
}
