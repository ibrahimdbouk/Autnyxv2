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
    /**
     * WP2.4 (audit L2): neutralise spreadsheet formula injection. Imported text
     * (SKUs, product names, notes) that starts with = + - @ is prefixed with an
     * apostrophe so Excel shows it as text instead of executing it. Real numbers
     * (and numeric strings like "-12.5") are left untouched.
     */
    public static function safeCell(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && ! is_numeric($value)
            && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }

    /** @param array<int|string,mixed> $row */
    public static function safeRow(array $row): array
    {
        return array_map([self::class, 'safeCell'], $row);
    }

    /** fputcsv with formula-injection protection. */
    public static function putcsv($handle, array $row): void
    {
        fputcsv($handle, self::safeRow($row), escape: '');
    }

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
