<?php

namespace App\Services\Anomaly;

use App\Models\Anomaly;
use App\Models\AuditLog;
use App\Models\User;

/**
 * W11 — one-click "is this real?" from the team.
 *
 *   real      the finding is a genuine problem (true positive). Recorded on
 *             the anomaly; nothing else changes.
 *   not_real  the data does not show a real problem (false positive). The
 *             anomaly is dismissed with that reason (AnomalyDismissal), which
 *             is also feedback to the detector.
 *
 * Every answer is kept — the precision of each rule on the tenant's own data
 * is real ÷ (real + not real) (QualityMetricsService, the value report).
 * A later answer replaces an earlier one; "real" after "not real" re-opens.
 */
class AnomalyFeedback
{
    public const REAL     = 'real';
    public const NOT_REAL = 'not_real';

    public const VIA_APP           = 'app';
    public const VIA_EMAIL         = 'email';
    public const VIA_ACTION_CENTER = 'action_center';
    public const VIA_STORE_SHEET   = 'store_sheet';   // W12

    public function __construct(private readonly AnomalyDismissal $dismissal) {}

    public function record(Anomaly $anomaly, string $verdict, ?User $by = null, string $via = self::VIA_APP, ?string $who = null): void
    {
        if (! in_array($verdict, [self::REAL, self::NOT_REAL], true)) {
            throw new \InvalidArgumentException("Unknown feedback [{$verdict}].");
        }
        if ($anomaly->feedback === $verdict) {
            return;
        }

        if ($verdict === self::NOT_REAL) {
            $this->dismissal->dismiss($anomaly, AnomalyDismissal::REASON_FALSE_POSITIVE, $by);
        } elseif ($anomaly->dismiss_reason === AnomalyDismissal::REASON_FALSE_POSITIVE) {
            // Someone said "not real" earlier; the team now says it is. Re-open it.
            $anomaly->update(['dismissed_at' => null, 'dismissed_by' => null, 'dismiss_reason' => null, 'is_false_positive' => false]);
        }

        $anomaly->forceFill([
            'feedback'     => $verdict,
            'feedback_at'  => now(),
            'feedback_by'  => $by?->id,
            'feedback_via' => $via,
        ])->save();

        AuditLog::create([
            'tenant_id'   => $anomaly->tenant_id,
            'anomaly_id'  => $anomaly->id,
            'user_id'     => $by?->id,
            'event_type'  => 'anomaly_feedback',
            'description' => 'Marked ' . ($verdict === self::REAL ? 'real' : 'not real')
                . " ({$anomaly->rule_type}" . ($anomaly->sku ? ", SKU {$anomaly->sku}" : '') . ") via {$via}"
                . ($who ? " by {$who}" : ''),
            'new_value'   => ['feedback' => $verdict, 'via' => $via, 'who' => $who],
        ]);
    }

    /**
     * Precision per rule from the team's answers (optionally since a date).
     *
     * @return array<string,array{real:int, not_real:int, precision:?float}>
     */
    public function precisionByRule(int $tenantId, ?\DateTimeInterface $since = null, ?\DateTimeInterface $until = null): array
    {
        $rows = Anomaly::where('tenant_id', $tenantId)->whereNotNull('feedback')
            ->when($since, fn ($q) => $q->where('feedback_at', '>=', $since))
            ->when($until, fn ($q) => $q->where('feedback_at', '<', $until))
            ->selectRaw("rule_type, COUNT(*) FILTER (WHERE feedback = 'real') AS r, COUNT(*) FILTER (WHERE feedback = 'not_real') AS n")
            ->groupBy('rule_type')->get();

        $out = [];
        foreach ($rows as $row) {
            $r = (int) $row->r;
            $n = (int) $row->n;
            $out[$row->rule_type] = ['real' => $r, 'not_real' => $n, 'precision' => $r + $n > 0 ? round(100 * $r / ($r + $n), 1) : null];
        }

        return $out;
    }
}
