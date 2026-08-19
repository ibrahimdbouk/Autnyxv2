<?php

namespace App\Services\Noise;

use App\Models\Anomaly;
use App\Models\Suppression;
use Illuminate\Support\Carbon;

/**
 * SuppressionService — Feature 6
 *
 * Enforces suppression at surfacing/correlation time. Anomalies are still
 * detected and recorded (history + FP learning preserved); suppression only
 * prevents them from opening/attaching to an investigation and notifying.
 *
 * Suppression NEVER feeds the adaptive-threshold path — only explicit
 * false-positive feedback (handled elsewhere) touches baselines.
 */
class SuppressionService
{
    /**
     * Find the active suppression that matches an anomaly, or null.
     */
    public function matchFor(Anomaly $anomaly): ?Suppression
    {
        $candidates = Suppression::query()
            ->currentlyActive()
            ->where('tenant_id', $anomaly->tenant_id)
            ->where('rule_type', $anomaly->rule_type)
            ->get();

        foreach ($candidates as $suppression) {
            if ($this->scopeMatches($suppression, $anomaly)) {
                return $suppression;
            }
        }

        return null;
    }

    public function isSuppressed(Anomaly $anomaly): bool
    {
        return $this->matchFor($anomaly) !== null;
    }

    /**
     * Record that a suppression matched (visibility of how much it is silencing).
     */
    public function recordMatch(Suppression $suppression): void
    {
        $suppression->increment('match_count');
        $suppression->forceFill(['last_matched_at' => now()])->save();
    }

    private function scopeMatches(Suppression $s, Anomaly $anomaly): bool
    {
        return match ($s->scope_type) {
            Suppression::SCOPE_RULE           => true,
            Suppression::SCOPE_RULE_STORE     => $s->store_id !== null && (int) $s->store_id === (int) $anomaly->store_id,
            Suppression::SCOPE_RULE_SKU       => $s->sku !== null && $s->sku === $anomaly->sku,
            Suppression::SCOPE_RULE_STORE_SKU => $s->store_id !== null && $s->sku !== null
                                                  && (int) $s->store_id === (int) $anomaly->store_id
                                                  && $s->sku === $anomaly->sku,
            default                           => false,
        };
    }

    /**
     * Create a suppression. Defaults to an expiry to avoid indefinite silence.
     */
    public function create(array $data, int $tenantId, ?int $createdBy = null): Suppression
    {
        $expires = $data['expires_at'] ?? null;
        if ($expires === null && ($data['default_expiry_days'] ?? 30)) {
            $expires = Carbon::now()->addDays((int) ($data['default_expiry_days'] ?? 30));
        }

        return Suppression::create([
            'tenant_id'  => $tenantId,
            'scope_type' => $data['scope_type'],
            'rule_type'  => $data['rule_type'],
            'sku'        => $data['sku'] ?? null,
            'store_id'   => $data['store_id'] ?? null,
            'reason'     => $data['reason'],
            'notes'      => $data['notes'] ?? null,
            'starts_at'  => $data['starts_at'] ?? now(),
            'expires_at' => $expires,
            'active'     => true,
            'created_by' => $createdBy,
        ]);
    }

    public function end(Suppression $suppression, ?int $endedBy = null): void
    {
        $suppression->update([
            'active'   => false,
            'ended_at' => now(),
            'ended_by' => $endedBy,
        ]);
    }

    /**
     * Expire suppressions whose window has passed. Returns count expired.
     */
    public function expireDue(?int $tenantId = null): int
    {
        $query = Suppression::where('active', true)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        $count = 0;
        foreach ($query->get() as $suppression) {
            $suppression->update(['active' => false, 'ended_at' => now()]);
            $count++;
        }
        return $count;
    }
}
