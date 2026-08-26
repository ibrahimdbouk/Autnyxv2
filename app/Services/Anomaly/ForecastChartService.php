<?php

namespace App\Services\Anomaly;

use App\Models\Anomaly;
use App\Models\SkuProfile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Renders the "demand vs best-fit forecast" picture for a demand_forecast_break
 * anomaly, as a self-contained inline SVG (no JS / chart library — deterministic,
 * theme-safe, prints).
 *
 * Designed for intermittent demand (most days are zero): actual demand is drawn
 * as BARS, the Croston/SBA forecast as a flat RATE line (seasonally adjusted per
 * day), the tolerance BAND shaded, and the recent window highlighted. That way a
 * lumpy series reads honestly instead of as a noisy line.
 */
class ForecastChartService
{
    private const DISPLAY_DAYS = 56; // 8 weeks
    private const RECENT_DAYS  = 7;
    private const BASE_TOL     = 0.5; // matches the rule's default pct band

    /** @return array{dates:string[],actual:float[],expected:float[],bandLow:float[],bandHigh:float[],recentFrom:int,rate:float,segment:string}|null */
    public function seriesForAnomaly(Anomaly $a): ?array
    {
        if ($a->rule_type !== 'demand_forecast_break' || $a->sku === null) return null;

        $tenantId = $a->tenant_id;
        $sku      = trim($a->sku);
        $alpha    = (float) ($a->context['alpha'] ?? 0.2);

        $from = Carbon::today()->subDays(self::DISPLAY_DAYS - 1);

        // Chain-level daily totals for the SKU.
        $byDate = [];
        DB::table('sales_daily')
            ->where('tenant_id', $tenantId)->where('sku', $sku)
            ->where('date', '>=', $from->format('Y-m-d'))
            ->selectRaw("TO_CHAR(date, 'YYYY-MM-DD') AS d, SUM(units_sold) AS u")
            ->groupBy('date')
            ->get()
            ->each(function ($r) use (&$byDate) { $byDate[$r->d] = (float) $r->u; });

        // Full daily axis (fill zeros), chronological.
        $dates = [];
        $actual = [];
        for ($i = 0; $i < self::DISPLAY_DAYS; $i++) {
            $d = $from->copy()->addDays($i)->format('Y-m-d');
            $dates[] = $d;
            $actual[] = $byDate[$d] ?? 0.0;
        }

        // Croston/SBA fit over the displayed series.
        $rate = $this->crostonRate($actual, $alpha);

        // Seasonal expected per day + tolerance band.
        $dow = app(SeasonalityService::class)->dayOfWeekFactors($tenantId, 90);
        $cv2 = (float) (DB::table('sku_profiles')->where('tenant_id', $tenantId)
            ->where('store_id', 0)->where('sku', $sku)->value('cv2') ?? 0);
        $tol = self::BASE_TOL * (1 + $cv2);

        $expected = $bandLow = $bandHigh = [];
        foreach ($dates as $d) {
            $f  = $dow[(int) Carbon::parse($d)->dayOfWeek] ?? 1.0;
            $ex = $rate * $f;
            $expected[]  = $ex;
            $bandLow[]   = max(0.0, $ex * (1 - $tol));
            $bandHigh[]  = $ex * (1 + $tol);
        }

        return [
            'dates'      => $dates,
            'actual'     => $actual,
            'expected'   => $expected,
            'bandLow'    => $bandLow,
            'bandHigh'   => $bandHigh,
            'recentFrom' => self::DISPLAY_DAYS - self::RECENT_DAYS,
            'rate'       => $rate,
            'segment'    => (string) ($a->context['segment'] ?? SkuProfile::SEG_INTERMITTENT),
        ];
    }

    /** Inline SVG string for the anomaly, or null when not a forecast anomaly. */
    public function svgForAnomaly(Anomaly $a): ?string
    {
        $s = $this->seriesForAnomaly($a);
        if ($s === null) return null;

        $W = 640; $H = 210;
        $padL = 34; $padR = 10; $padT = 26; $padB = 22;
        $plotW = $W - $padL - $padR;
        $plotH = $H - $padT - $padB;
        $n = count($s['dates']);

        $maxY = max(1.0, max($s['actual']), max($s['bandHigh']));
        $maxY *= 1.1;

        $x = fn (int $i) => $padL + ($n <= 1 ? 0 : $plotW * $i / ($n - 1));
        $y = fn (float $v) => $padT + $plotH - ($plotH * min($v, $maxY) / $maxY);

        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $fmt = fn (float $v) => rtrim(rtrim(number_format($v, 1), '0'), '.');

        // Tolerance band polygon (high across, then low back).
        $pts = [];
        for ($i = 0; $i < $n; $i++) $pts[] = round($x($i), 1) . ',' . round($y($s['bandHigh'][$i]), 1);
        for ($i = $n - 1; $i >= 0; $i--) $pts[] = round($x($i), 1) . ',' . round($y($s['bandLow'][$i]), 1);
        $band = implode(' ', $pts);

        // Expected (forecast) polyline.
        $exLine = [];
        for ($i = 0; $i < $n; $i++) $exLine[] = round($x($i), 1) . ',' . round($y($s['expected'][$i]), 1);
        $exLine = implode(' ', $exLine);

        // Actual bars.
        $barW = max(1.5, $plotW / $n * 0.6);
        $bars = '';
        for ($i = 0; $i < $n; $i++) {
            $v = $s['actual'][$i];
            if ($v <= 0) continue;
            $bx = $x($i) - $barW / 2;
            $by = $y($v);
            $bh = ($padT + $plotH) - $by;
            $recent = $i >= $s['recentFrom'];
            $fill = $recent ? '#b45309' : '#94a3b8';
            $bars .= '<rect x="' . round($bx, 1) . '" y="' . round($by, 1) . '" width="' . round($barW, 1)
                . '" height="' . round($bh, 1) . '" fill="' . $fill . '" rx="1"/>';
        }

        // Recent-window divider + shading.
        $divX = round($x($s['recentFrom']), 1);
        $recentShade = '<rect x="' . $divX . '" y="' . $padT . '" width="' . round(($padL + $plotW) - $divX, 1)
            . '" height="' . $plotH . '" fill="#fef3c7" opacity="0.35"/>';
        $divider = '<line x1="' . $divX . '" y1="' . $padT . '" x2="' . $divX . '" y2="' . ($padT + $plotH)
            . '" stroke="#d97706" stroke-width="1" stroke-dasharray="3 3"/>';

        // Y axis: 0 and max ticks.
        $axis = '<line x1="' . $padL . '" y1="' . ($padT + $plotH) . '" x2="' . ($padL + $plotW) . '" y2="' . ($padT + $plotH) . '" stroke="#e5e7eb"/>'
            . '<text x="' . ($padL - 5) . '" y="' . ($padT + $plotH) . '" text-anchor="end" font-size="9" fill="#9ca3af">0</text>'
            . '<text x="' . ($padL - 5) . '" y="' . ($padT + 8) . '" text-anchor="end" font-size="9" fill="#9ca3af">' . $e($fmt($maxY)) . '</text>';

        // X labels: first, divider, last.
        $xlab = '<text x="' . $padL . '" y="' . ($H - 6) . '" font-size="9" fill="#9ca3af">' . $e(Carbon::parse($s['dates'][0])->format('M j')) . '</text>'
            . '<text x="' . ($padL + $plotW) . '" y="' . ($H - 6) . '" text-anchor="end" font-size="9" fill="#9ca3af">today</text>'
            . '<text x="' . $divX . '" y="' . ($H - 6) . '" text-anchor="middle" font-size="9" fill="#d97706">last ' . self::RECENT_DAYS . 'd</text>';

        // Legend.
        $legend = '<g font-size="9" fill="#6b7280">'
            . '<rect x="' . $padL . '" y="10" width="9" height="9" fill="#94a3b8" rx="1"/><text x="' . ($padL + 13) . '" y="18">Actual</text>'
            . '<rect x="' . ($padL + 60) . '" y="10" width="9" height="9" fill="#b45309" rx="1"/><text x="' . ($padL + 73) . '" y="18">Recent</text>'
            . '<line x1="' . ($padL + 130) . '" y1="15" x2="' . ($padL + 146) . '" y2="15" stroke="#7c3aed" stroke-width="2"/><text x="' . ($padL + 150) . '" y="18">Best-fit forecast</text>'
            . '<rect x="' . ($padL + 250) . '" y="10" width="16" height="9" fill="#7c3aed" opacity="0.15"/><text x="' . ($padL + 270) . '" y="18">Tolerance band</text>'
            . '</g>';

        return '<svg viewBox="0 0 ' . $W . ' ' . $H . '" width="100%" role="img" xmlns="http://www.w3.org/2000/svg" '
            . 'style="max-width:640px;font-family:ui-sans-serif,system-ui,sans-serif;background:#fff;border:1px solid #f3f4f6;border-radius:8px">'
            . $recentShade
            . '<polygon points="' . $band . '" fill="#7c3aed" opacity="0.12"/>'
            . $axis
            . $bars
            . '<polyline points="' . $exLine . '" fill="none" stroke="#7c3aed" stroke-width="2"/>'
            . $divider
            . $legend
            . $xlab
            . '</svg>';
    }

    /** Streaming Croston/SBA rate over a chronological daily series. */
    private function crostonRate(array $daily, float $alpha): float
    {
        $z = 0.0; $p = 0.0; $occ = 0; $q = 0;
        foreach ($daily as $u) {
            $q++;
            if ($u > 0) {
                $occ++;
                if ($occ === 1) { $z = (float) $u; $p = max(1, $q); }
                else { $z += $alpha * ($u - $z); $p += $alpha * ($q - $p); }
                $q = 0;
            }
        }

        return $p > 0 ? ($z / $p) * (1 - $alpha / 2) : 0.0;
    }
}
