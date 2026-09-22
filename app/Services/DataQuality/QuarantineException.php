<?php

namespace App\Services\DataQuality;

/**
 * Thrown from the per-row write path (writeRow) when the firewall rejects a row, so
 * the caller diverts it to quarantine instead of the failed-row ledger. Carries the
 * cleansed row so the quarantine record keeps both raw and cleansed values.
 */
class QuarantineException extends \RuntimeException
{
    public function __construct(public readonly string $reasonCode, public readonly array $cleansed = [])
    {
        parent::__construct($reasonCode);
    }
}
