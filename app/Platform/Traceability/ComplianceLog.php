<?php

namespace App\Platform\Traceability;

use App\Models\Batch;
use App\Models\ComplianceEvent;
use Illuminate\Support\Collection;

/**
 * P3.3 — the compliance audit trail: record temperature excursions, expiry
 * breaches, recalls, quarantine and disposal against a batch, and query what is
 * still open. Recall is a first-class operation because it is the scenario the
 * whole batch model exists to serve.
 */
class ComplianceLog
{
    public function record(
        int $tenantId,
        string $eventType,
        ?int $batchId = null,
        string $severity = ComplianceEvent::SEVERITY_WARNING,
        ?string $detail = null,
    ): ComplianceEvent {
        return ComplianceEvent::create([
            'tenant_id'   => $tenantId,
            'batch_id'    => $batchId,
            'event_type'  => $eventType,
            'severity'    => $severity,
            'detail'      => $detail,
            'occurred_at' => now(),
        ]);
    }

    /**
     * Recall a batch: flip its status and log a critical recall event in one step.
     */
    public function recall(Batch $batch, ?string $detail = null): ComplianceEvent
    {
        $batch->update(['status' => Batch::STATUS_RECALLED]);

        return $this->record(
            $batch->tenant_id,
            ComplianceEvent::TYPE_RECALL,
            $batch->id,
            ComplianceEvent::SEVERITY_CRITICAL,
            $detail ?? "Batch {$batch->batch_code} recalled",
        );
    }

    /**
     * Unresolved compliance events for a tenant, most recent first.
     *
     * @return Collection<int,ComplianceEvent>
     */
    public function open(int $tenantId): Collection
    {
        return ComplianceEvent::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('resolved_at')
            ->orderByDesc('occurred_at')
            ->get();
    }

    /**
     * Cold-chain breaches (temperature excursions) for a tenant.
     *
     * @return Collection<int,ComplianceEvent>
     */
    public function coldChainBreaches(int $tenantId): Collection
    {
        return ComplianceEvent::query()
            ->where('tenant_id', $tenantId)
            ->where('event_type', ComplianceEvent::TYPE_TEMPERATURE_EXCURSION)
            ->orderByDesc('occurred_at')
            ->get();
    }

    public function resolve(ComplianceEvent $event): ComplianceEvent
    {
        $event->update(['resolved_at' => now()]);

        return $event;
    }
}
