<?php

namespace App\Platform\Governance;

use App\Models\UsageMeter;
use Illuminate\Support\Collection;

/**
 * P3.4 — per-tenant, per-app usage metering. Apps call record() as they do
 * billable work (an investigation opened, a detection run, an API call); the
 * platform aggregates it per app/metric/period. This is what makes usage
 * metered/billable and lets each app be packaged and priced independently.
 */
class MeteringService
{
    /** Increment a usage counter. Period defaults to the current month (YYYY-MM). */
    public function record(int $tenantId, string $app, string $metric, int $n = 1, ?string $period = null): UsageMeter
    {
        $period ??= now()->format('Y-m');

        $meter = UsageMeter::firstOrCreate(
            ['tenant_id' => $tenantId, 'app' => $app, 'metric' => $metric, 'period' => $period],
            ['count' => 0],
        );
        $meter->increment('count', $n);

        return $meter->refresh();
    }

    /** Total for one metric of one app in a period. */
    public function usage(int $tenantId, string $app, string $metric, ?string $period = null): int
    {
        $period ??= now()->format('Y-m');

        return (int) UsageMeter::query()
            ->where('tenant_id', $tenantId)
            ->where('app', $app)
            ->where('metric', $metric)
            ->where('period', $period)
            ->sum('count');
    }

    /**
     * A tenant's usage for a period, one row per app+metric.
     *
     * @return Collection<int,array{app:string,metric:string,count:int}>
     */
    public function tenantUsage(int $tenantId, ?string $period = null): Collection
    {
        $period ??= now()->format('Y-m');

        return UsageMeter::query()
            ->where('tenant_id', $tenantId)
            ->where('period', $period)
            ->orderBy('app')
            ->orderBy('metric')
            ->get()
            ->map(fn (UsageMeter $m) => ['app' => $m->app, 'metric' => $m->metric, 'count' => $m->count]);
    }

    /** Total usage for one app across all its metrics in a period. */
    public function appTotal(int $tenantId, string $app, ?string $period = null): int
    {
        $period ??= now()->format('Y-m');

        return (int) UsageMeter::query()
            ->where('tenant_id', $tenantId)
            ->where('app', $app)
            ->where('period', $period)
            ->sum('count');
    }
}
