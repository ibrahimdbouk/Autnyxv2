<?php

namespace App\Platform\Integration;

use App\Models\OutboundDispatch;
use App\Models\OutboundTarget;

/**
 * P2.1 — dispatch a canonical action-intent to the tenant's replenishment system
 * of record and record the round-trip. Resolves the tenant's active target (or
 * falls back to the log connector when none is configured), delivers via the
 * matching connector, and persists an OutboundDispatch row for every attempt —
 * success or failure. Never throws: a delivery failure is recorded, not raised.
 */
class OutboundDispatcher
{
    public function __construct(private readonly ConnectorFactory $factory)
    {
    }

    public function dispatch(ActionIntent $intent): OutboundDispatch
    {
        $target = OutboundTarget::query()
            ->where('tenant_id', $intent->tenantId)
            ->where('active', true)
            ->first();

        $dispatch = OutboundDispatch::create([
            'tenant_id'       => $intent->tenantId,
            'target_id'       => $target?->id,
            'intent_type'     => $intent->intentType,
            'source'          => $intent->source,
            'request_payload' => $intent->toArray(),
            'status'          => OutboundDispatch::STATUS_PENDING,
            'dispatched_at'   => now(),
        ]);

        // No configured target → a transient log target, so the path still runs.
        $effectiveTarget = $target ?? new OutboundTarget([
            'tenant_id' => $intent->tenantId,
            'kind'      => OutboundTarget::KIND_LOG,
        ]);

        $result = $this->factory->for($effectiveTarget->kind)->dispatch($intent, $effectiveTarget);

        $dispatch->update([
            'status'        => $result->status,
            'response_code' => $result->code,
            'response_body' => $result->body,
            'completed_at'  => now(),
        ]);

        return $dispatch->refresh();
    }
}
