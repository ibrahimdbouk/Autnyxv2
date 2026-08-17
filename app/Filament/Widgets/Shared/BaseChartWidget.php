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
