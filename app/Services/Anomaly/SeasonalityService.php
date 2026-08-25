<?php

namespace App\Services\Anomaly;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Seasonality primitives the detection rules consult so that a demand move which
 * is merely *expected for the calendar* is not mistaken for an anomaly.
 *
 * Two horizons:
 *   - dayOfWeekFactors(): chain-level weekday rhythm (weekend-heavy grocery, etc.).
 *     Computable from a few weeks of data. Note it is ~neutral over windows that
 *     are whole weeks, so it matters for odd-length windows and intra-week checks.
 *   - monthlyFactors(): month-of-year rhythm (holiday/seasonal peaks). Only
 *     meaningful once there is roughly a year of history; with less it returns
 *     near-neutral factors, and callers should treat weak factors as "no signal".
 *
 * A factor of 1.0 is neutral; >1 means that bucket is busier than average.
 */
class SeasonalityService
{
    /** @return array<int,float> 0=Sunday..6=Saturday (Postgres DOW) => factor */
    public function dayOfWeekFactors(int $tenantId, int $days = 90): array
    {
        $from = Carbon::today()->subDays($days)->format('Y-m-d');

        $rows = DB::select(
            "SELECT EXTRACT(DOW FROM date)::int AS dow,
                    SUM(units_sold)        AS units,
                    COUNT(DISTINCT date)   AS ndays
             FROM sales_daily
             WHERE tenant_id = ? AND date >= ?
             GROUP BY EXTRACT(DOW FROM date)::int",
            [$tenantId, $from]
        );

        $perDow = [];
        $totUnits = 0.0;
        $totDays  = 0;
        foreach ($rows as $r) {
            $perDow[(int) $r->dow] = ['u' => (float) $r->units, 'n' => (int) $r->ndays];
            $totUnits += (float) $r->units;
            $totDays  += (int) $r->ndays;
        }
        if ($totDays <= 0 || $totUnits <= 0) return [];

        $overallAvg = $totUnits / $totDays;
        $factors = [];
        for ($d = 0; $d < 7; $d++) {
            $e = $perDow[$d] ?? null;
            $factors[$d] = ($e && $e['n'] > 0 && $overallAvg > 0)
                ? round(($e['u'] / $e['n']) / $overallAvg, 3)
                : 1.0;
        }

        return $factors;
    }

    /** @return array<int,float> 1=Jan..12=Dec => factor (near-neutral until ~1yr of data) */
    public function monthlyFactors(int $tenantId, int $days = 400): array
    {
        $from = Carbon::today()->subDays($days)->format('Y-m-d');

        $rows = DB::select(
            "SELECT EXTRACT(MONTH FROM date)::int AS mon,
                    SUM(units_sold)      AS units,
                    COUNT(DISTINCT date) AS ndays
             FROM sales_daily
             WHERE tenant_id = ? AND date >= ?
             GROUP BY EXTRACT(MONTH FROM date)::int",
            [$tenantId, $from]
        );

        $perMon = [];
        $totUnits = 0.0;
        $totDays  = 0;
        foreach ($rows as $r) {
            $perMon[(int) $r->mon] = ['u' => (float) $r->units, 'n' => (int) $r->ndays];
            $totUnits += (float) $r->units;
            $totDays  += (int) $r->ndays;
        }
        if ($totDays <= 0 || $totUnits <= 0) return [];

        $overallAvg = $totUnits / $totDays;
        $factors = [];
        for ($m = 1; $m <= 12; $m++) {
            $e = $perMon[$m] ?? null;
            $factors[$m] = ($e && $e['n'] > 0 && $overallAvg > 0)
                ? round(($e['u'] / $e['n']) / $overallAvg, 3)
                : 1.0;
        }

        return $factors;
    }

    /**
     * True when the month factors carry real signal — i.e. there is roughly a
     * year of history (≥ $minMonths distinct months present with data). Below
     * that, monthly seasonality is noise and callers should skip the adjustment.
     */
    public function hasSeasonalHistory(int $tenantId, int $minMonths = 10): bool
    {
        $months = (int) DB::table('sales_daily')
            ->where('tenant_id', $tenantId)
            ->distinct()
            ->count(DB::raw("DATE_TRUNC('month', date)"));

        return $months >= $minMonths;
    }

    /**
     * Expected units for a set of calendar dates, given a per-day baseline rate
     * and day-of-week factors. Sum of baselineDaily × factor(dow) over the dates.
     * With week-multiple windows this ≈ baselineDaily × count(dates).
     *
     * @param array<int,float> $dowFactors
     * @param string[]         $dates  Y-m-d strings
     */
    public function expectedUnits(float $baselineDaily, array $dowFactors, array $dates): float
    {
        if (empty($dowFactors)) return $baselineDaily * count($dates);

        $sum = 0.0;
        foreach ($dates as $d) {
            $dow  = (int) Carbon::parse($d)->dayOfWeek; // 0=Sun..6=Sat, matches Postgres DOW
            $sum += $baselineDaily * ($dowFactors[$dow] ?? 1.0);
        }

        return $sum;
    }
}
