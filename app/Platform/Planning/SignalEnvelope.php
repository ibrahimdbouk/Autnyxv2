<?php

namespace App\Platform\Planning;

use App\Models\PlanningSignal;

/**
 * P2.4 (outbound) — the canonical, versioned envelope for one feed signal handed
 * to the tenant's planning system (or its iPaaS). Mirror of P2.1's ActionIntent
 * envelope, but for the *sensing feed* rather than a single decision: a stable
 * shape a receiver maps once, whatever the downstream is.
 */
class SignalEnvelope
{
    public const CONTRACT_VERSION = '1.0';

    public function __construct(private readonly PlanningSignal $signal)
    {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'tenant_id'        => $this->signal->tenant_id,
            'signal_id'        => $this->signal->id,
            'signal_type'      => $this->signal->signal_type,
            'subject'          => [
                'sku'      => $this->signal->sku,
                'store_id' => $this->signal->store_id,
            ],
            'severity'    => $this->signal->severity,
            'delta'       => $this->signal->delta,
            'rationale'   => $this->signal->rationale,
            'objective'   => $this->signal->objective,
            'source'      => $this->signal->source,
            'detected_at' => optional($this->signal->detected_at)->toIso8601String(),
        ];
    }
}
