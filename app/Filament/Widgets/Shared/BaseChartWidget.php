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
 *   protected static ?string $heading = 'Sales Trend';
 *
 *   protected function getData(): array { ... }   // return Chart.js dataset format
 *   protected function getType(): string { return 'line'; }
 */
abstract class BaseChartWidget extends ChartWidget
{
    protected static ?string $pollingInterval = null;

    protected int | string | array $columnSpan = 'full';

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
