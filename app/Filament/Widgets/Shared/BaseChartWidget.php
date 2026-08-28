<?php

namespace App\Filament\Widgets\Shared;

use Filament\Widgets\ChartWidget;

/**
 * Base class for all Autnyx chart widgets.
 *
 * Drop onto any page and it renders the chart.
 * Override getData() and getType() in the subclass.
 *
 * Usage:
 *   protected ?string $heading = 'Sales Trend';   // non-static in Filament v5
 *
 *   protected function getData(): array { ... }   // return Chart.js dataset format
 *   protected function getType(): string { return 'line'; }
 */
abstract class BaseChartWidget extends ChartWidget
{
    protected ?string $pollingInterval = null;

    protected int | string | array $columnSpan = 'full';

    /* ── Design-system chart palette ──────────────────────────────────────
     | One source of truth for chart colors, mirroring the CSS tokens in
     | public/css/autnyx-ui.css (--ax-chart-* / --ax-accent / status). Keeps
     | every widget on the same violet-led categorical scale and the same
     | semantic status colors, instead of ad-hoc per-widget hexes.
     */

    /** Accent + semantic status (match --ax-accent / --ax-success/warning/danger/info). */
    protected const AX_ACCENT  = '#7c3aed';
    protected const AX_SUCCESS = '#16a34a';
    protected const AX_WARNING = '#d97706';
    protected const AX_DANGER  = '#dc2626';
    protected const AX_INFO    = '#2563eb';

    /** Categorical series palette (violet-led), for charts colored by series/category. */
    protected const AX_SERIES = [
        '#7c3aed', // violet (accent)
        '#2563eb', // blue
        '#0d9488', // teal
        '#d97706', // amber
        '#db2777', // pink
        '#65a30d', // lime
        '#a855f7', // purple
        '#0ea5e9', // sky
        '#f43f5e', // rose
        '#84cc16', // green
    ];

    /**
     * Grid-line color that reads on BOTH light and dark backgrounds.
     * (The old per-widget `rgba(0,0,0,0.05)` vanished in dark mode.) A mid
     * slate at low alpha sits between the two grounds. Filament themes the
     * tick/legend text color per mode already; the grid is what we must pin.
     */
    protected const AX_GRID = 'rgba(148,163,184,0.22)';

    /** hex → rgba(...) with the given alpha, for soft fills under lines/bars. */
    protected static function axFill(string $hex, float $alpha = 0.12): string
    {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        return "rgba({$r},{$g},{$b},{$alpha})";
    }

    /**
     * Filter options shown in the chart header.
     * Override to add a date range or period filter.
     */
    protected function getFilters(): ?array
    {
        return [
            '7d'  => 'Last 7 days',
            '30d' => 'Last 30 days',
            '90d' => 'Last 90 days',
        ];
    }
}
