<?php

namespace App\Platform\Integration;

use App\Models\OutboundTarget;
use App\Platform\Integration\Connectors\LogConnector;
use App\Platform\Integration\Connectors\WebhookConnector;
use App\Platform\Integration\Contracts\OutboundConnector;

/**
 * P2.1 — resolves a target kind to its connector. Unbuilt flagship kinds
 * (s4/d365/oracle/relex/…) fall through to the LogConnector, so declaring a
 * target of that kind is safe and records the intent until the real connector
 * ships. This is the single switch point — the only place kind→connector lives.
 */
class ConnectorFactory
{
    public function for(string $kind): OutboundConnector
    {
        return match ($kind) {
            OutboundTarget::KIND_WEBHOOK => new WebhookConnector(),
            // 'log' and every not-yet-built flagship kind → safe log connector.
            default => new LogConnector(),
        };
    }
}
