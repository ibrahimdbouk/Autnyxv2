<?php

namespace App\Platform\Planning;

use App\Models\PlanningSignal;
use Illuminate\Support\Collection;

/**
 * P2.4 (outbound) — the sensing feed Autnyx publishes back into planning. Apps
 * (root-cause detection/recovery) publish exceptions, root-cause findings and
 * recovery signals here; the tenant's planning system pulls the unconsumed feed
 * as canonical {@see SignalEnvelope}s and acknowledges what it took. This is the
 * "export root-cause/recovery signals back into planning" half of the bidirectional
 * loop — continuous, distinct from P2.1's single action-intent write-back.
 */
class PlanningSignalFeed
{
    /** Publish one signal into the feed. */
    public function publish(
        int $tenantId,
        string $signalType,
        ?string $sku = null,
        ?int $storeId = null,
        string $severity = PlanningSignal::SEVERITY_INFO,
        ?float $delta = null,
        ?string $rationale = null,
        ?string $objective = null,
        ?string $source = null,
    ): PlanningSignal {
        return PlanningSignal::create([
            'tenant_id'   => $tenantId,
            'signal_type' => $signalType,
            'sku'         => $sku,
            'store_id'    => $storeId,
            'severity'    => $severity,
            'delta'       => $delta,
            'rationale'   => $rationale,
            'objective'   => $objective,
            'source'      => $source,
            'detected_at' => now(),
        ]);
    }

    /**
     * The unconsumed feed as canonical envelopes, oldest first.
     *
     * @return Collection<int,array<string,mixed>>
     */
    public function feed(int $tenantId, int $limit = 500): Collection
    {
        return PlanningSignal::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('consumed_at')
            ->orderBy('detected_at')
            ->limit($limit)
            ->get()
            ->map(fn (PlanningSignal $s) => (new SignalEnvelope($s))->toArray());
    }

    /**
     * Acknowledge signals as consumed by the downstream system.
     *
     * @param  iterable<int>  $signalIds
     */
    public function markConsumed(int $tenantId, iterable $signalIds): int
    {
        return PlanningSignal::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', collect($signalIds)->all())
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);
    }
}
