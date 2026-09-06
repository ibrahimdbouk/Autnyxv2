<?php

namespace App\Platform\Integration;

/**
 * P2.1 — the outcome of one connector dispatch: a status plus the target's
 * response (HTTP code + body, where applicable).
 */
class DispatchResult
{
    public const STATUS_SENT         = 'sent';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';
    public const STATUS_FAILED       = 'failed';

    public function __construct(
        public readonly string $status,
        public readonly ?int $code = null,
        public readonly ?string $body = null,
    ) {
    }
}
