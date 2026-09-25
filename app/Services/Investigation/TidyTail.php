<?php

namespace App\Services\Investigation;

use App\Filament\Pages\ActionQueue;
use App\Models\AuditLog;
use App\Models\Investigation;

/**
 * WP4.5 — the other half of queue:tidy-tail: an auto-snoozed tail item comes
 * back to the active queue when it stops being "a small trend" — when an
 * incident-type anomaly joins it, or when its revenue at risk climbs to the
 * materiality floor. Only system (auto_low_value_tail) snoozes are lifted; a
 * snooze a person chose is left alone.
 */
class TidyTail
{
    public const REASON = 'auto_low_value_tail';

    public static function floor(): float
    {
        return (float) config('detection.trend_min_revenue', 2000);
    }

    /** @return string[] */
    public static function trendRules(): array
    {
        $rules = [];
        foreach (ActionQueue::campaignsMap() as $cfg) {
            if (($cfg['kind'] ?? '') === 'trend') {
                $rules = array_merge($rules, $cfg['rules']);
            }
        }

        return array_values(array_unique($rules));
    }

    public static function isAutoSnoozed(Investigation $investigation): bool
    {
        return $investigation->snooze_reason === self::REASON
            && $investigation->snoozed_until !== null && $investigation->snoozed_until->isFuture();
    }

    /** Lift the auto-snooze if the item is no longer a small, purely-trend one. */
    public static function resurfaceIfWarranted(Investigation $investigation, ?string $joinedRule = null): bool
    {
        if (! self::isAutoSnoozed($investigation)) {
            return false;
        }

        $why = null;
        if ($joinedRule !== null && ! in_array($joinedRule, self::trendRules(), true)) {
            $why = "an incident signal ({$joinedRule}) joined it";
        } elseif ((float) $investigation->revenue_at_risk >= self::floor()) {
            $why = 'its revenue at risk reached the materiality floor';
        }
        if ($why === null) {
            return false;
        }

        $investigation->update([
            'snoozed_until' => null, 'snooze_reason' => null, 'snooze_notes' => null, 'snoozed_by' => null, 'snoozed_at' => null,
        ]);
        AuditLog::create([
            'tenant_id'        => $investigation->tenant_id,
            'investigation_id' => $investigation->id,
            'event_type'       => 'investigation_resurfaced',
            'description'      => "Back in the queue: {$why}.",
        ]);

        return true;
    }
}
