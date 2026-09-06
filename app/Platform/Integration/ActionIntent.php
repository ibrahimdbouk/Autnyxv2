<?php

namespace App\Platform\Integration;

/**
 * P2.1 — the canonical action-intent: the ONE outbound decision shape Autnyx
 * emits, independent of any target system. Apps build this from their own domain
 * objects (an Action, a recommendation) and hand it to the {@see OutboundDispatcher};
 * connectors translate it into a target's shape. Building this once — and adapting
 * only at the connector edge — is what stops a bespoke integration per app.
 */
class ActionIntent
{
    public const CONTRACT_VERSION = '1.0';

    /**
     * @param  array<string,mixed>  $metadata
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly string $intentType,        // reorder | transfer | price_adjustment | write_off | …
        public readonly ?string $sku = null,
        public readonly ?int $storeId = null,
        public readonly ?int $productId = null,
        public readonly ?float $quantity = null,
        public readonly ?string $targetDate = null,
        public readonly ?string $rationale = null,
        public readonly ?float $expectedValue = null,
        public readonly ?string $objective = null,
        public readonly ?string $source = null,     // e.g. "action:123"
        public readonly array $metadata = [],
    ) {
    }

    /**
     * The canonical, versioned envelope sent to a target. Stable shape so a
     * receiver (or the customer's iPaaS) can map it once.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'tenant_id'        => $this->tenantId,
            'intent_type'      => $this->intentType,
            'subject'          => [
                'sku'        => $this->sku,
                'store_id'   => $this->storeId,
                'product_id' => $this->productId,
            ],
            'quantity'       => $this->quantity,
            'target_date'    => $this->targetDate,
            'rationale'      => $this->rationale,
            'expected_value' => $this->expectedValue,
            'objective'      => $this->objective,
            'source'         => $this->source,
            'metadata'       => $this->metadata,
            'emitted_at'     => now()->toIso8601String(),
        ];
    }
}
