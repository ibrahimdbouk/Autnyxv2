<?php

namespace App\Support;

use App\Models\AuditLog;
use Throwable;

/**
 * 3b — record data exports (PDF / Excel downloads) in the append-only audit log.
 * Best-effort: an audit failure must never block a download.
 */
class ExportAudit
{
    public static function log(int $tenantId, string $what, string $format): void
    {
        try {
            AuditLog::create([
                'tenant_id'   => $tenantId,
                'user_id'     => auth()->id(),
                'event_type'  => AuditLog::EVENT_DATA_EXPORTED,
                'description' => 'Exported ' . $what . ' (' . strtoupper($format) . ')',
            ]);
        } catch (Throwable $e) {
            // best-effort
        }
    }
}
