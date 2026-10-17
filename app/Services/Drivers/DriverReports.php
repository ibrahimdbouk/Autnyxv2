<?php

namespace App\Services\Drivers;

use App\Models\Anomaly;
use App\Models\SalesDriverAnalysis;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Root Cause drivers — which periods to compare, the stored weekly
 * breakdowns, and the breakdown behind one sales finding.
 *
 * Weekly: the last complete Monday–Sunday week of sales against the same week
 * last year (52 weeks back, the same weekdays) when that week has the data,
 * else against the 4 weeks before it.
 */
class DriverReports
{
    public const KEEP_WEEKS = 104;

    /** Sales rules a breakdown explains. */
    public const SALES_RULES = ['sales_drop', 'sales_spike', 'store_outlier', 'demand_seasonality_breach', 'demand_forecast_break', 'demand_erosion'];

    public function __construct(private readonly DriverAnalysis $engine)
    {
    }

    /** The newest day with sales, or null. */
    public function latestSalesDate(int $tenantId): ?string
    {
        $d = DB::table('store_daily')->where('tenant_id', $tenantId)->max('date');

        return $d ? substr((string) $d, 0, 10) : null;
    }

    /**
     * @return array{from:string,to:string,cfrom:string,cto:string,kind:string}|null
     */
    public function weeklyPeriods(int $tenantId, ?string $asOf = null): ?array
    {
        $latest = $asOf ?? $this->latestSalesDate($tenantId);
        if ($latest === null) {
            return null;
        }
        $end = Carbon::parse($latest);
        $end = $end->isSunday() ? $end : $end->copy()->previous(Carbon::SUNDAY);
        $from = $end->copy()->subDays(6)->toDateString();
        $to = $end->toDateString();

        return $this->comparisonFor($tenantId, $from, $to);
    }

    /** Same period last year when it has the data, else the 4 weeks before. */
    public function comparisonFor(int $tenantId, string $from, string $to, ?string $prefer = null): array
    {
        $days = DriverAnalysis::days($from, $to);
        $yoyFrom = Carbon::parse($from)->subDays(364)->toDateString();
        $yoyTo = Carbon::parse($to)->subDays(364)->toDateString();
        $storeDays = fn (string $a, string $b) => (int) DB::table('store_daily')->where('tenant_id', $tenantId)->whereBetween('date', [$a, $b])->count();
        $now = $storeDays($from, $to);
        $yoyOk = $now > 0 && $storeDays($yoyFrom, $yoyTo) >= 0.8 * $now
            && (int) DB::table('store_daily')->where('tenant_id', $tenantId)->whereBetween('date', [$yoyFrom, $yoyTo])->distinct()->count('date') >= $days;
        if ($prefer !== 'prior4w' && $yoyOk) {
            return ['from' => $from, 'to' => $to, 'cfrom' => $yoyFrom, 'cto' => $yoyTo, 'kind' => 'yoy'];
        }

        return ['from' => $from, 'to' => $to, 'cfrom' => Carbon::parse($from)->subDays(28)->toDateString(),
            'cto' => Carbon::parse($from)->subDay()->toDateString(), 'kind' => 'prior4w'];
    }

    public static function scopeKey(array $scope): string
    {
        $parts = [];
        if (! empty($scope['store_id'])) {
            $parts[] = 'store:' . (int) $scope['store_id'];
        }
        if (isset($scope['department'])) {
            $parts[] = 'dept:' . $scope['department'];
        }
        if (isset($scope['category'])) {
            $parts[] = 'cat:' . $scope['category'];
        }

        return $parts === [] ? 'business' : mb_substr(implode('|', $parts), 0, 200);
    }

    /**
     * The breakdown for a scope and periods: the stored one while its data has
     * not changed, else computed and stored.
     */
    public function get(int $tenantId, array $scope, array $periods, string $kind = 'custom', bool $force = false): SalesDriverAnalysis
    {
        $key = self::scopeKey($scope);
        $row = SalesDriverAnalysis::where('tenant_id', $tenantId)->where('scope_key', $key)
            ->where('period_from', $periods['from'])->where('period_to', $periods['to'])
            ->where('comparison_from', $periods['cfrom'])->where('comparison_to', $periods['cto'])->first();
        if ($row && ! $force && $row->version === DriverAnalysis::VERSION && ! $this->dataChangedSince($tenantId, $periods, $row->computed_at)) {
            return $row;
        }
        $r = $this->engine->analyze($tenantId, $periods['from'], $periods['to'], $periods['cfrom'], $periods['cto'], $scope, $periods['kind']);

        $row ??= new SalesDriverAnalysis(['tenant_id' => $tenantId, 'scope_key' => $key, 'period_from' => $periods['from'], 'period_to' => $periods['to'],
            'comparison_from' => $periods['cfrom'], 'comparison_to' => $periods['cto']]);
        $row->fill([
            'kind'            => $row->exists && $row->kind === 'weekly' ? 'weekly' : $kind,
            'comparison_kind' => $periods['kind'],
            'change'          => $r['totals']['change'],
            'unexplained'     => DriverAnalysis::map($r['why'])['unexplained'] ?? 0,
            'result'          => $r,
            'version'         => DriverAnalysis::VERSION,
            'computed_at'     => now(),
        ])->save();

        return $row;
    }

    private function dataChangedSince(int $tenantId, array $p, $at): bool
    {
        $latest = DB::table('store_daily')->where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->whereBetween('date', [$p['from'], $p['to']])->orWhereBetween('date', [$p['cfrom'], $p['cto']]))
            ->max('updated_at');

        return $latest !== null && Carbon::parse($latest)->gt($at);
    }

    /**
     * The nightly weekly set: the business, every store that sold that week,
     * every department.
     *
     * @return array{analyses:int, periods:?array}
     */
    public function computeWeekly(int $tenantId, bool $force = false): array
    {
        $p = $this->weeklyPeriods($tenantId);
        if ($p === null) {
            return ['analyses' => 0, 'periods' => null];
        }
        $n = 0;
        $this->get($tenantId, [], $p, 'weekly', $force);
        $n++;
        foreach (DB::table('store_daily')->where('tenant_id', $tenantId)->whereBetween('date', [$p['from'], $p['to']])->distinct()->pluck('store_id') as $storeId) {
            $this->get($tenantId, ['store_id' => (int) $storeId], $p, 'weekly', $force);
            $n++;
        }
        foreach (DB::table('category_daily')->where('tenant_id', $tenantId)->whereBetween('date', [$p['from'], $p['to']])->distinct()->pluck('department') as $dept) {
            $this->get($tenantId, ['department' => (string) $dept], $p, 'weekly', $force);
            $n++;
        }
        SalesDriverAnalysis::where('tenant_id', $tenantId)->where('period_to', '<', now()->subWeeks(self::KEEP_WEEKS)->toDateString())->delete();
        SalesDriverAnalysis::where('tenant_id', $tenantId)->where('kind', 'custom')->where('computed_at', '<', now()->subDays(30))->delete();

        return ['analyses' => $n, 'periods' => $p];
    }

    /** The latest weekly breakdown of a scope. */
    public function latestWeekly(int $tenantId, array $scope = []): ?SalesDriverAnalysis
    {
        return SalesDriverAnalysis::where('tenant_id', $tenantId)->where('scope_key', self::scopeKey($scope))->where('kind', 'weekly')
            ->orderByDesc('period_to')->first();
    }

    /**
     * The breakdown behind one sales finding: its own window against the
     * comparison its rule used (the 28 days before; the same days last year
     * for a year-on-year seasonality finding).
     */
    public function forFinding(Anomaly $a): ?array
    {
        if (! in_array($a->rule_type, self::SALES_RULES, true) || ! $a->sku) {
            return null;
        }
        $ctx = (array) ($a->context ?? []);
        $window = $ctx['window'] ?? null;
        if (is_array($window) && count($window) >= 2) {
            [$from, $to] = [substr((string) $window[0], 0, 10), substr((string) $window[1], 0, 10)];
        } else {
            $latest = DB::table('sales_daily')->where('tenant_id', $a->tenant_id)->where('sku', $a->sku)
                ->when($a->store_id, fn ($q) => $q->where('store_id', $a->store_id))
                ->where('date', '<=', ($a->detected_at ?? now())->toDateString())->max('date');
            if (! $latest) {
                return null;
            }
            $to = substr((string) $latest, 0, 10);
            $days = (int) ($ctx['days'] ?? 7);
            $from = Carbon::parse($to)->subDays(max(1, $days) - 1)->toDateString();
        }
        $yoy = ($ctx['mode'] ?? null) === 'yoy';
        $cfrom = $yoy ? Carbon::parse($from)->subDays(364)->toDateString() : Carbon::parse($from)->subDays(28)->toDateString();
        $cto = $yoy ? Carbon::parse($to)->subDays(364)->toDateString() : Carbon::parse($from)->subDay()->toDateString();

        $r = $this->engine->analyze((int) $a->tenant_id, $from, $to, $cfrom, $cto,
            array_filter(['sku' => trim((string) $a->sku), 'store_id' => $a->store_id]), $yoy ? 'yoy' : 'prior4w');

        return $r + ['explained' => self::explained($r)];
    }

    /**
     * How a finding's movement is explained: by stock (≥ 50% of the change
     * is out-of-stock days, the same way), or by promotions and events
     * (≥ 70%), or not.
     *
     * @return array{by:?string, share:float, availability_share:float, calendar_share:float}
     */
    public static function explained(array $r): array
    {
        $chg = (float) $r['totals']['change'];
        $why = DriverAnalysis::map($r['why']);
        $share = fn (float $v) => abs($chg) > 0.005 && ($v > 0) === ($chg > 0) ? min(1.0, abs($v) / abs($chg)) : 0.0;
        $avail = $share($why['availability'] ?? 0);
        $calendar = $share(($why['promotions'] ?? 0) + ($why['events'] ?? 0));
        $by = $avail >= 0.5 ? 'availability' : ($calendar >= 0.7 ? 'calendar' : null);

        return ['by' => $by, 'share' => round(max($avail, $calendar), 3), 'availability_share' => round($avail, 3), 'calendar_share' => round($calendar, 3)];
    }
}
