<?php

namespace App\Services\Assortment;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * v2.2 — seasonal curves. A product is judged on its last weeks (the benchmark
 * window), and "a year" was that window × 365 / its days. For a category that
 * sells twice as much in summer, a summer window overstates the year and a
 * winter one understates it. With a full year of the tenant's own sales, each
 * category gets a shape:
 *
 *   month curve   each month's sales per day ÷ the year's sales per day (chain-wide)
 *   window index  the window's sales per day ÷ the year's sales per day
 *   a year        the window's figure ÷ the window index
 *
 * Under a year of history there is no curve and nothing is adjusted. An index
 * within ±10% of 1 is treated as no season (noise), and it is bounded to
 * 0.5–2.0 so one odd month cannot swing a decision. Products that sell mostly in
 * one season are already kept from delists by their lifecycle (Phase 0).
 *
 * Demand on out-of-stock days needs no extra step here: every rate is per day IN
 * STOCK (GroupData), so a product that keeps running out is judged on the days it
 * could sell, and a stockout fix is valued on the days it was out.
 */
class SeasonalIndex
{
    public const MIN_DAYS = 365;

    public const DEAD_BAND = 0.10;

    public const MIN = 0.5;

    public const MAX = 2.0;

    /**
     * @return array<string, array{index: float, applied: bool, months: array<int,float>, window_days: int}> category => season
     */
    public function forTenant(int $tenantId, string $asOf, ?int $windowDays = null): array
    {
        $windowDays ??= GroupData::window();

        return Cache::remember("assortment:seasons:{$tenantId}:{$asOf}:{$windowDays}", now()->addHours(6),
            fn () => $this->build($tenantId, $asOf, $windowDays));
    }

    /** What to multiply a window-based "a year" by for this category (1.0 when there is no season). */
    public static function factor(array $seasons, ?string $category): float
    {
        $s = $category !== null ? ($seasons[$category] ?? null) : null;

        return $s !== null && $s['applied'] ? 1 / $s['index'] : 1.0;
    }

    private function build(int $tenantId, string $asOf, int $windowDays): array
    {
        $end = Carbon::parse($asOf);
        $from = $end->copy()->subDays(self::MIN_DAYS - 1)->toDateString();
        $first = DB::table('sales_daily')->where('tenant_id', $tenantId)->min('date');
        if ($first === null || Carbon::parse($first)->gt(Carbon::parse($from))) {
            return [];   // under a year of history: no curve
        }
        $winFrom = $end->copy()->subDays($windowDays - 1)->toDateString();

        $rows = DB::select(
            "SELECT TRIM(p.category) AS cat, EXTRACT(MONTH FROM sd.date)::int AS m,
                    SUM(CASE WHEN sd.revenue > 0 THEN sd.revenue ELSE sd.units_sold * COALESCE(p.selling_price, 0) END) AS rev,
                    SUM(CASE WHEN sd.date >= ? THEN (CASE WHEN sd.revenue > 0 THEN sd.revenue ELSE sd.units_sold * COALESCE(p.selling_price, 0) END) ELSE 0 END) AS win
               FROM sales_daily sd JOIN products p ON p.tenant_id = sd.tenant_id AND p.sku = sd.sku
              WHERE sd.tenant_id = ? AND sd.date BETWEEN ? AND ? AND p.category IS NOT NULL AND TRIM(p.category) <> ''
           GROUP BY 1, 2",
            [$winFrom, $tenantId, $from, $asOf],
        );

        // Days of each month inside the year, for sales per day.
        $daysIn = array_fill(1, 12, 0);
        for ($d = Carbon::parse($from); $d->lte($end); $d->addDay()) {
            $daysIn[(int) $d->month]++;
        }

        $by = [];
        foreach ($rows as $r) {
            $by[(string) $r->cat]['months'][(int) $r->m] = (float) $r->rev;
            $by[(string) $r->cat]['win'] = ($by[(string) $r->cat]['win'] ?? 0.0) + (float) $r->win;
        }

        $out = [];
        foreach ($by as $cat => $v) {
            $total = array_sum($v['months']);
            if ($total <= 0) {
                continue;
            }
            $perDay = $total / self::MIN_DAYS;
            $months = [];
            for ($m = 1; $m <= 12; $m++) {
                $months[$m] = $daysIn[$m] > 0 ? round((($v['months'][$m] ?? 0.0) / $daysIn[$m]) / $perDay, 3) : 0.0;
            }
            $index = max(self::MIN, min(self::MAX, ($v['win'] / $windowDays) / $perDay));
            $out[$cat] = [
                'index'       => round($index, 3),
                'applied'     => abs($index - 1) > self::DEAD_BAND,
                'months'      => $months,
                'window_days' => $windowDays,
            ];
        }

        return $out;
    }

    /** "sells 140% of its usual in these weeks" — for the explanation. */
    public static function line(array $seasons, ?string $category): ?string
    {
        $s = $category !== null ? ($seasons[$category] ?? null) : null;
        if ($s === null || ! $s['applied']) {
            return null;
        }

        return "Seasonal: {$category} sells " . (int) round($s['index'] * 100) . '% of its usual in these weeks, so a year\'s figure is adjusted for that.';
    }
}
