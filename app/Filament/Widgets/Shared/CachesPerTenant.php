<?php

namespace App\Filament\Widgets\Shared;

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;

/**
 * WP6.4 (audit H32) — dashboard figures are computed at most once per tenant
 * every couple of minutes, not on every render and every poll by every user.
 */
trait CachesPerTenant
{
    protected function cachedForTenant(string $key, \Closure $compute, int $ttl = 120): mixed
    {
        $tenantId = Filament::getTenant()?->id;
        if (! $tenantId) {
            return $compute();
        }

        return Cache::remember('dash:' . $tenantId . ':' . class_basename(static::class) . ':' . $key, $ttl, $compute);
    }
}
