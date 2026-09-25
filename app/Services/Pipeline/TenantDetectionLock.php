<?php

namespace App\Services\Pipeline;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * WP5.2 (audit H3) — one detection writer per tenant at a time. The nightly
 * chain, import-triggered runs, manual runs and recalibration all take the same
 * lock, held in the DATABASE cache store so it is shared by the web, worker and
 * scheduler machines (the default file store is per machine).
 */
final class TenantDetectionLock
{
    /** Longest a run may hold it before it is considered abandoned. */
    public const TTL = 3600;

    public static function for(int $tenantId): Lock
    {
        return Cache::store(config('pipeline.lock_store', 'database'))->lock('tenant-detect-' . $tenantId, self::TTL);
    }
}
