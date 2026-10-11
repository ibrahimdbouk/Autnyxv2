<?php

namespace App\Support;

/**
 * v2.5 — the arithmetic behind the server-drawn charts
 * (resources/views/components/ui/viz): round axis ticks, linear scales, heat
 * shades and series colours. No data of its own — each screen's service works
 * the figures out; this only places them.
 */
final class Viz
{
    public const SERIES = 8;

    /**
     * Round ticks covering [min, max] — about $n of them (1, 2, 2.5 or 5 × 10^k apart).
     *
     * @return list<float>
     */
    public static function ticks(float $min, float $max, int $n = 5): array
    {
        if (! is_finite($min) || ! is_finite($max)) {
            return [0.0];
        }
        if ($max < $min) {
            [$min, $max] = [$max, $min];
        }
        if (abs($max - $min) < 1e-12) {
            $pad = abs($min) > 0 ? abs($min) * 0.1 : 1.0;
            [$min, $max] = [$min - $pad, $max + $pad];
        }
        $raw = ($max - $min) / max(1, $n);
        $mag = 10 ** floor(log10($raw));
        $step = $mag;
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            if ($m * $mag >= $raw) {
                $step = $m * $mag;
                break;
            }
        }
        $out = [];
        for ($v = floor($min / $step) * $step; $v <= $max + $step * 0.5; $v += $step) {
            $out[] = round($v, 10);
            if (count($out) > 50) {
                break;
            }
        }

        return $out;
    }

    /** A value's position between two pixel ends. */
    public static function scale(float $v, float $d0, float $d1, float $r0, float $r1): float
    {
        if (abs($d1 - $d0) < 1e-12) {
            return ($r0 + $r1) / 2;
        }

        return round($r0 + ($v - $d0) / ($d1 - $d0) * ($r1 - $r0), 2);
    }

    /** The CSS class of series $i (1-based, wraps). */
    public static function series(int $i): string
    {
        return 'ax-viz-s' . ((max(1, $i) - 1) % self::SERIES + 1);
    }

    /**
     * A heat cell's colour: a class and its strength (the fill-opacity attribute).
     *
     *   diverging   index around 1.0 (1 = like the chain): above one hue, below the other
     *   good        a share 0–1 where more is better (fades from red at 0 to green at 1)
     *   bad         a share 0–1 where more is worse
     *
     * @return array{class:string, opacity:float}
     */
    public static function heat(?float $v, string $mode = 'diverging', float $spread = 0.6): array
    {
        if ($v === null || ! is_finite($v)) {
            return ['class' => 'ax-viz-cell--empty', 'opacity' => 1.0];
        }
        if ($mode === 'diverging') {
            $d = $v - 1.0;
            $t = min(1.0, abs($d) / max(1e-9, $spread));

            return ['class' => $d >= 0 ? 'ax-viz-heat-up' : 'ax-viz-heat-down', 'opacity' => round(0.08 + 0.82 * $t, 2)];
        }
        $v = max(0.0, min(1.0, $v));
        if ($mode === 'bad') {
            return ['class' => 'ax-viz-heat-bad', 'opacity' => round(0.08 + 0.82 * $v, 2)];
        }
        // good: under half reads as a problem (red, stronger the lower), over half as healthy (green).
        return $v < 0.5
            ? ['class' => 'ax-viz-heat-bad', 'opacity' => round(0.1 + 0.8 * (0.5 - $v) / 0.5, 2)]
            : ['class' => 'ax-viz-heat-good', 'opacity' => round(0.1 + 0.8 * ($v - 0.5) / 0.5, 2)];
    }

    /** Whether a cell's number reads better in white (a strong fill) than in ink. */
    public static function onDark(array $heat): bool
    {
        return $heat['class'] !== 'ax-viz-cell--empty' && $heat['opacity'] >= 0.6;
    }

    /** A label cut to fit $chars characters (the full text stays in the <title>). */
    public static function cut(string $s, int $chars): string
    {
        return mb_strlen($s) > $chars ? rtrim(mb_substr($s, 0, max(1, $chars - 1))) . '…' : $s;
    }

    /** One end-to-end SVG path for an arc of a donut ring, from angle a0 to a1 (radians, 0 = top). */
    public static function arc(float $cx, float $cy, float $r, float $r0, float $a0, float $a1): string
    {
        $a1 = min($a1, $a0 + 2 * M_PI - 1e-4);
        $p = fn (float $rad, float $a) => [round($cx + $rad * sin($a), 2), round($cy - $rad * cos($a), 2)];
        [$x0, $y0] = $p($r, $a0);
        [$x1, $y1] = $p($r, $a1);
        [$x2, $y2] = $p($r0, $a1);
        [$x3, $y3] = $p($r0, $a0);
        $large = ($a1 - $a0) > M_PI ? 1 : 0;

        return "M{$x0} {$y0} A{$r} {$r} 0 {$large} 1 {$x1} {$y1} L{$x2} {$y2} A{$r0} {$r0} 0 {$large} 0 {$x3} {$y3} Z";
    }
}
