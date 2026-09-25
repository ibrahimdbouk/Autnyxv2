<?php

namespace App\Services\Anomaly;

use App\Models\Anomaly;
use App\Models\CustomRuleDefinition;

/**
 * W10 (WP10.6) — what happens to a tenant rule's anomalies when the rule
 * changes. When a rule is deleted, switched off or rewritten, the anomalies
 * it raised stop being its findings: they are set aside as superseded — not
 * "resolved", since nothing was fixed (so they never count as recovery). A
 * rewritten rule raises fresh ones on its next run.
 *
 * Lives on the Root Cause side: the platform's rule definitions know nothing
 * of anomalies (config/architecture.php).
 */
final class CustomRuleHits
{
    public static function register(): void
    {
        CustomRuleDefinition::deleted(fn (CustomRuleDefinition $r) => self::supersede($r));
        CustomRuleDefinition::updated(function (CustomRuleDefinition $r) {
            if (($r->wasChanged('active') && ! $r->active) || $r->wasChanged('formula') || $r->wasChanged('condition')) {
                self::supersede($r);
            }
        });
    }

    public static function supersede(CustomRuleDefinition $r): int
    {
        return Anomaly::where('tenant_id', $r->tenant_id)->where('rule_type', 'custom_rule')
            ->where('context->custom_key', $r->key)->active()
            ->update(['dismissed_at' => now(), 'dismiss_reason' => AnomalyDismissal::REASON_SUPERSEDED]);
    }
}
