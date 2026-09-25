<?php

namespace App\Services\Anomaly;

use App\Models\Anomaly;
use App\Models\AuditLog;
use App\Models\User;

/**
 * WP4.3 (audit H14) — the one way an anomaly is dismissed.
 *
 * The person says why. Only "false positive" is feedback about the detector
 * (it widens that rule's sensitivity for the SKU, capped and audited); "known
 * cause", "not actionable" and "duplicate" just close the anomaly. Detection
 * then keeps the subject quiet until its condition materially worsens
 * (AnomalyDetectionService::flagV2).
 */
class AnomalyDismissal
{
    public const REASON_FALSE_POSITIVE = 'false_positive';
    public const REASON_KNOWN_CAUSE    = 'known_cause';
    public const REASON_NOT_ACTIONABLE = 'not_actionable';
    public const REASON_DUPLICATE      = 'duplicate';

    public const REASONS = [
        self::REASON_FALSE_POSITIVE => 'False positive — the data doesn\'t show a real problem',
        self::REASON_KNOWN_CAUSE    => 'Known cause — expected (promotion, range change, …)',
        self::REASON_NOT_ACTIONABLE => 'Real, but not worth acting on',
        self::REASON_DUPLICATE      => 'Duplicate of another anomaly',
    ];

    public function __construct(private readonly BaselineCalculatorService $baselines) {}

    public function dismiss(Anomaly $anomaly, string $reason, ?User $by = null): void
    {
        if (! array_key_exists($reason, self::REASONS)) {
            throw new \InvalidArgumentException("Unknown dismissal reason [{$reason}].");
        }
        if ($anomaly->dismissed_at !== null) {
            return;
        }

        $falsePositive = $reason === self::REASON_FALSE_POSITIVE;

        $anomaly->update([
            'dismissed_at'      => now(),
            'dismissed_by'      => $by?->id,
            'dismiss_reason'    => $reason,
            'is_false_positive' => $falsePositive,
        ]);

        AuditLog::create([
            'tenant_id'   => $anomaly->tenant_id,
            'anomaly_id'  => $anomaly->id,
            'user_id'     => $by?->id,
            'event_type'  => 'anomaly_dismissed',
            'description' => "Dismissed {$anomaly->rule_type}" . ($anomaly->sku ? " for SKU {$anomaly->sku}" : '') . " — {$reason}",
            'new_value'   => ['reason' => $reason],
        ]);

        if ($falsePositive) {
            $this->baselines->recordFalsePositive((int) $anomaly->tenant_id, (string) $anomaly->rule_type, $anomaly->sku, $by?->id);
        }
    }
}
