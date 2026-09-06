<?php

namespace App\Platform\Planning;

/**
 * P2.4 — the canonical inbound forecast point. A tenant's F&R/ERP planning
 * system produces these (however its own API is shaped); a connector maps its
 * rows into this one shape, and {@see ForecastIngestor} persists them as the
 * planning baseline. One shape in, regardless of source (RELEX / BY / Slimstock /
 * S4 MRP / D365 / Oracle Fusion) — the same "adapt only at the edge" principle
 * P2.1 uses outbound.
 */
class ForecastPoint
{
    public function __construct(
        public readonly string $sku,
        public readonly string $targetDate,          // Y-m-d
        public readonly float $forecastQty,
        public readonly ?int $storeId = null,         // null = chain-level
        public readonly ?float $plannedOrderQty = null,
        public readonly ?string $sourceRef = null,
        public readonly ?int $horizonDays = null,
        public readonly ?string $generatedAt = null,  // ISO8601, when upstream generated it
    ) {
    }
}
