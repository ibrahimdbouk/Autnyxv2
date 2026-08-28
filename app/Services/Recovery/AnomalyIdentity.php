<?php

namespace App\Services\Recovery;

use App\Models\Anomaly;

/**
 * Deterministic persistent identity for an anomaly's subject.
 *
 * Formalises the detection layer's existing M15 dedup key — an anomaly is "the
 * same signal as last night" when its tenant, rule and that rule's own subject
 * fields match. In this codebase every rule flags through a single creator with
 * (sku, store_id), so the subject signature is (store_id, sku); a rule that
 * doesn't set one leaves it null and it drops out of the key naturally
 * (store-level rules key on store; SKU-chain rules key on SKU).
 *
 * The key is DERIVED, not decided — it can be recomputed at any time from the
 * anomaly's own columns. That is why it is safe to materialise at creation; the
 * reconciliation job (R2) remains the single writer of lifecycle *status*.
 */
final class AnomalyIdentity
{
    /** Sentinel for an absent subject field, so null is a distinct, stable key part. */
    private const NIL = "\u{2205}"; // ∅

    public static function key(int $tenantId, string $ruleType, ?int $storeId, ?string $sku): string
    {
        $store = $storeId !== null ? (string) $storeId : self::NIL;
        $skuNorm = ($sku !== null && trim($sku) !== '') ? trim($sku) : self::NIL;

        return hash('sha1', $tenantId . '|' . $ruleType . '|' . $store . '|' . $skuNorm);
    }

    public static function forAnomaly(Anomaly $anomaly): string
    {
        return self::key(
            (int) $anomaly->tenant_id,
            (string) $anomaly->rule_type,
            $anomaly->store_id !== null ? (int) $anomaly->store_id : null,
            $anomaly->sku,
        );
    }
}
