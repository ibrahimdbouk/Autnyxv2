<?php

namespace App\Services\Noise;

use App\Models\AuditLog;
use App\Models\Investigation;
use App\Services\AuditLogger;
use Illuminate\Support\Carbon;

/**
 * SnoozeService — Feature 6 (Snooze = temporary silence on one investigation)
 *
 * Snoozes return automatically once snoozed_until passes. Snooze does NOT feed
 * false-positive learning and does NOT delete history.
 */
class SnoozeService
{
    const REASONS = [
        'known_issue'            => 'Known issue',
        'planned_promotion'      => 'Planned promotion',
        'store_closure'          => 'Store closure',
        'maintenance'            => 'Maintenance',
        'known_supplier_problem' => 'Known supplier problem',
        'data_issue'             => 'Data issue',
        'false_positive'         => 'False positive',
        'other'                  => 'Other',
    ];

    const DURATIONS = [
        '24h'    => '24 hours',
        '3d'     => '3 days',
        '7d'     => '7 days',
        '30d'    => '30 days',
        'custom' => 'Custom date',
    ];

    public function resolveUntil(string $duration, ?string $customDate = null): Carbon
    {
        return match ($duration) {
            '24h'    => now()->addDay(),
            '3d'     => now()->addDays(3),
            '7d'     => now()->addDays(7),
            '30d'    => now()->addDays(30),
            'custom' => $customDate ? Carbon::parse($customDate) : now()->addDay(),
            default  => now()->addDay(),
        };
    }

    public function snooze(Investigation $investigation, Carbon $until, string $reason, ?string $notes = null, ?int $userId = null): void
    {
        $investigation->update([
            'snoozed_until' => $until,
            'snooze_reason' => $reason,
            'snooze_notes'  => $notes,
            'snoozed_by'    => $userId,
            'snoozed_at'    => now(),
        ]);

        AuditLogger::log(
            $investigation,
            AuditLog::EVENT_SNOOZED,
            'Snoozed until ' . $until->toDayDateTimeString() . ' (' . (self::REASONS[$reason] ?? $reason) . ')',
            $userId
        );
    }

    public function unsnooze(Investigation $investigation, ?int $userId = null): void
    {
        if ($investigation->snoozed_until === null) {
            return;
        }
        $investigation->update([
            'snoozed_until' => null,
            'snooze_reason' => null,
            'snooze_notes'  => null,
            'snoozed_by'    => null,
            'snoozed_at'    => null,
        ]);

        AuditLogger::log($investigation, AuditLog::EVENT_UNSNOOZED, 'Snooze cleared', $userId);
    }

    /**
     * Clear snoozes whose window has passed. Returns count cleared.
     */
    public function clearExpired(?int $tenantId = null): int
    {
        $query = Investigation::whereNotNull('snoozed_until')
            ->where('snoozed_until', '<=', now());

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        $count = 0;
        foreach ($query->get() as $investigation) {
            $investigation->update([
                'snoozed_until' => null,
                'snooze_reason' => null,
                'snooze_notes'  => null,
                'snoozed_by'    => null,
                'snoozed_at'    => null,
            ]);
            $count++;
        }
        return $count;
    }
}
