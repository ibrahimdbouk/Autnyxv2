<?php

namespace App\Services\Metrics;

use App\Models\AnomalySetting;
use App\Models\Investigation;
use App\Models\User;
use App\Services\Org\OrgDirectory;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * v2.5 — where the money at risk sits (Root Cause visual layer), worked out
 * from the open investigations only (open or in progress — a closed one no
 * longer puts money at risk):
 *
 *   byDepartment   store × department: the revenue at risk of the open
 *                  investigations whose main product is in that department
 *   byProblem      store × kind of problem: open findings (anomalies) on open
 *                  investigations
 *   topStores      the stores with the most revenue at risk
 *
 * Someone with a store scope sees their stores only. Read-only; cached five
 * minutes per tenant and scope.
 */
class RiskMap
{
    public const CACHE_SECONDS = 300;

    public const OPEN = [Investigation::STATUS_OPEN, Investigation::STATUS_IN_PROGRESS];

    /** @return array<int,int>|null the stores a person may see (null = all) */
    private function scope(?User $user): ?array
    {
        return $user ? app(OrgDirectory::class)->storeScope($user) : null;
    }

    private function key(string $what, int $tenantId, ?array $scope): string
    {
        return "riskmap:{$what}:{$tenantId}:" . ($scope === null ? 'all' : md5(json_encode($scope)));
    }

    /** @return array{rows: list<array>, cols: list<array>, cells: array, max: float}|null */
    public function byDepartment(int $tenantId, ?User $user, string $currency, int $stores = 15, int $departments = 10): ?array
    {
        $scope = $this->scope($user);

        return Cache::remember($this->key('dept', $tenantId, $scope), self::CACHE_SECONDS, function () use ($tenantId, $scope, $currency, $stores, $departments) {
            $q = DB::table('investigations as i')
                ->leftJoin('products as p', fn ($j) => $j->on('p.tenant_id', '=', 'i.tenant_id')->on('p.sku', '=', 'i.primary_sku'))
                ->leftJoin('stores as s', 's.id', '=', 'i.primary_store_id')
                ->where('i.tenant_id', $tenantId)->whereIn('i.status', self::OPEN)->whereNotNull('i.primary_store_id')
                ->selectRaw("i.primary_store_id AS store_id, MAX(s.name) AS store, COALESCE(NULLIF(TRIM(p.department), ''), NULLIF(TRIM(p.category), ''), 'No department') AS dept,
                    SUM(COALESCE(i.revenue_at_risk, 0)) AS value, COUNT(*) AS n")
                ->groupByRaw("i.primary_store_id, COALESCE(NULLIF(TRIM(p.department), ''), NULLIF(TRIM(p.category), ''), 'No department')");
            if ($scope !== null) {
                $q->whereIn('i.primary_store_id', $scope);
            }
            $rows = $q->get();
            if ($rows->isEmpty() || (float) $rows->sum('value') <= 0) {
                return null;
            }
            $storeTotals = $rows->groupBy('store_id')->map(fn ($g) => (float) $g->sum('value'))->sortDesc()->take($stores);
            $deptTotals = $rows->groupBy('dept')->map(fn ($g) => (float) $g->sum('value'))->sortDesc()->take($departments);
            $names = $rows->pluck('store', 'store_id');
            $cells = [];
            $max = 0.0;
            foreach ($rows as $r) {
                if (isset($storeTotals[$r->store_id], $deptTotals[$r->dept])) {
                    $max = max($max, (float) $r->value);
                }
            }
            foreach ($rows as $r) {
                if (! isset($storeTotals[$r->store_id], $deptTotals[$r->dept]) || (float) $r->value <= 0) {
                    continue;
                }
                $cells[(int) $r->store_id][(string) $r->dept] = [
                    'v'    => $max > 0 ? (float) $r->value / $max : null,
                    'text' => Money::compact((float) $r->value, $currency),
                    'tip'  => $names[$r->store_id] . ' · ' . $r->dept . ': ' . Money::compact((float) $r->value, $currency) . ' at risk across '
                        . $r->n . ' open investigation' . ($r->n > 1 ? 's' : ''),
                ];
            }

            return [
                'rows'  => $storeTotals->map(fn ($v, $id) => ['key' => (int) $id, 'label' => (string) ($names[$id] ?? 'Store ' . $id), 'sub' => Money::compact($v, $currency)])->values()->all(),
                'cols'  => $deptTotals->map(fn ($v, $d) => ['key' => (string) $d, 'label' => (string) $d])->values()->all(),
                'cells' => $cells,
                'max'   => $max,
                'shown' => (float) $storeTotals->sum(),
                'total' => (float) $rows->sum('value'),
            ];
        });
    }

    /** @return array{rows: list<array>, cols: list<array>, cells: array, max: int}|null */
    public function byProblem(int $tenantId, ?User $user, int $stores = 20, int $kinds = 8): ?array
    {
        $scope = $this->scope($user);

        return Cache::remember($this->key('problem', $tenantId, $scope), self::CACHE_SECONDS, function () use ($tenantId, $scope, $stores, $kinds) {
            $q = DB::table('anomalies as a')
                ->join('investigations as i', 'i.id', '=', 'a.investigation_id')
                ->leftJoin('stores as s', 's.id', '=', 'a.store_id')
                ->where('a.tenant_id', $tenantId)->whereIn('i.status', self::OPEN)->whereNull('a.dismissed_at')->whereNotNull('a.store_id')
                ->selectRaw('a.store_id, MAX(s.name) AS store, a.rule_type, COUNT(*) AS n')
                ->groupBy('a.store_id', 'a.rule_type');
            if ($scope !== null) {
                $q->whereIn('a.store_id', $scope);
            }
            $rows = $q->get();
            if ($rows->isEmpty()) {
                return null;
            }
            $storeTotals = $rows->groupBy('store_id')->map(fn ($g) => (int) $g->sum('n'))->sortDesc()->take($stores);
            $kindTotals = $rows->groupBy('rule_type')->map(fn ($g) => (int) $g->sum('n'))->sortDesc()->take($kinds);
            $names = $rows->pluck('store', 'store_id');
            $label = fn (string $rule) => AnomalySetting::RULES[$rule]['label'] ?? ucwords(str_replace('_', ' ', $rule));
            $max = (int) $rows->filter(fn ($r) => isset($storeTotals[$r->store_id], $kindTotals[$r->rule_type]))->max('n');
            $cells = [];
            foreach ($rows as $r) {
                if (! isset($storeTotals[$r->store_id], $kindTotals[$r->rule_type])) {
                    continue;
                }
                $cells[(int) $r->store_id][(string) $r->rule_type] = [
                    'v'    => $max > 0 ? (int) $r->n / $max : null,
                    'text' => (string) (int) $r->n,
                    'tip'  => $names[$r->store_id] . ' · ' . $label((string) $r->rule_type) . ': ' . (int) $r->n . ' open finding' . ((int) $r->n > 1 ? 's' : ''),
                ];
            }

            return [
                'rows'  => $storeTotals->map(fn ($v, $id) => ['key' => (int) $id, 'label' => (string) ($names[$id] ?? 'Store ' . $id), 'sub' => $v . ' findings'])->values()->all(),
                'cols'  => $kindTotals->map(fn ($v, $k) => ['key' => (string) $k, 'label' => $label((string) $k)])->values()->all(),
                'cells' => $cells,
                'max'   => $max,
            ];
        });
    }

    /** @return list<array{label:string, value:float, text:string, tip:string, s:int}> */
    public function topStores(int $tenantId, ?User $user, string $currency, int $limit = 15): array
    {
        $scope = $this->scope($user);

        return Cache::remember($this->key('top', $tenantId, $scope), self::CACHE_SECONDS, function () use ($tenantId, $scope, $currency, $limit) {
            $q = DB::table('investigations as i')->leftJoin('stores as s', 's.id', '=', 'i.primary_store_id')
                ->where('i.tenant_id', $tenantId)->whereIn('i.status', self::OPEN)->whereNotNull('i.primary_store_id')
                ->selectRaw("i.primary_store_id AS id, MAX(s.name) AS name, SUM(COALESCE(i.revenue_at_risk, 0)) AS value, COUNT(*) AS n,
                    SUM(CASE WHEN i.priority IN ('critical', 'high') THEN 1 ELSE 0 END) AS urgent")
                ->groupBy('i.primary_store_id')->orderByDesc('value')->limit($limit);
            if ($scope !== null) {
                $q->whereIn('i.primary_store_id', $scope);
            }

            return $q->get()->filter(fn ($r) => (float) $r->value > 0)->map(fn ($r) => [
                'label' => (string) ($r->name ?? 'Store ' . $r->id),
                'value' => (float) $r->value,
                'text'  => Money::compact((float) $r->value, $currency),
                'tip'   => ($r->name ?? 'Store ' . $r->id) . ': ' . Money::compact((float) $r->value, $currency) . ' at risk on ' . $r->n . ' open investigation(s), ' . $r->urgent . ' high or critical',
                // Red where most of the store's open work is urgent.
                'class' => (int) $r->urgent * 2 > (int) $r->n ? 'ax-viz-down' : 'ax-viz-s1',
            ])->values()->all();
        });
    }
}
