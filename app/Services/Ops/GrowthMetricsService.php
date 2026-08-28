<?php

namespace App\Services\Ops;

use App\Models\Anomaly;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ops — platform growth & usage trends. Cross-tenant, count-based (never sums
 * money across tenants, which carry different currencies), cheap grouped
 * aggregates for the ops home charts.
 */
class GrowthMetricsService
{
    /** @return array<string,int> Y-m-d → new tenants that day */
    public function newTenantsDaily(int $days = 30): array
    {
        return $this->dailyCount('tenants', 'created_at', $days);
    }

    /** @return array<string,int> Y-m-d → new users that day */
    public function newUsersDaily(int $days = 30): array
    {
        return $this->dailyCount('users', 'created_at', $days);
    }

    /** @return array<string,int> Y-m-d → anomalies detected that day (all tenants) */
    public function anomaliesDetectedDaily(int $days = 30): array
    {
        return $this->dailyCount('anomalies', 'detected_at', $days);
    }

    /** @return array<string,int> Y-m-d → episodes resolved that day (all tenants) */
    public function resolvedDaily(int $days = 30): array
    {
        return $this->dailyCount('anomalies', 'resolved_at', $days, [
            'lifecycle_state' => Anomaly::LIFECYCLE_RESOLVED,
        ]);
    }

    /** @return array<string,int> Y-m-d → ingestion runs that day */
    public function ingestionDaily(int $days = 30): array
    {
        return $this->dailyCount('ingestion_runs', 'created_at', $days);
    }

    /** Cumulative totals for the headline tiles. */
    public function totals(): array
    {
        return [
            'tenants'          => Tenant::count(),
            'users'            => User::count(),
            'tenants_this_month' => Tenant::where('created_at', '>=', now()->startOfMonth())->count(),
            'users_this_month'   => User::where('created_at', '>=', now()->startOfMonth())->count(),
            'anomalies_30d'    => Anomaly::where('detected_at', '>=', now()->subDays(30))->count(),
            'resolved_30d'     => Anomaly::where('lifecycle_state', Anomaly::LIFECYCLE_RESOLVED)
                ->where('resolved_at', '>=', now()->subDays(30))->count(),
        ];
    }

    /**
     * Fill a daily map to a continuous $days-long series ending today, as an
     * ordered array of ['date'=>'M j','value'=>int].
     *
     * @param  array<string,int>  $map
     * @return array<int,array{date:string,value:int}>
     */
    public function series(array $map, int $days = 30): array
    {
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = now()->subDays($i);
            $out[] = [
                'date'  => $d->format('M j'),
                'value' => (int) ($map[$d->format('Y-m-d')] ?? 0),
            ];
        }

        return $out;
    }

    // ── Internal ────────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>|null  $where
     * @return array<string,int>
     */
    private function dailyCount(string $table, string $col, int $days, ?array $where = null): array
    {
        $from = now()->subDays($days - 1)->startOfDay();

        $q = DB::table($table)->whereNotNull($col)->where($col, '>=', $from);
        foreach ($where ?? [] as $k => $v) {
            $q->where($k, $v);
        }

        return $q->selectRaw("TO_CHAR({$col}::date, 'YYYY-MM-DD') AS d, COUNT(*) AS c")
            ->groupByRaw("TO_CHAR({$col}::date, 'YYYY-MM-DD')")
            ->pluck('c', 'd')
            ->map(fn ($v) => (int) $v)
            ->all();
    }
}
