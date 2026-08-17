<?php

namespace App\Filament\Widgets\Shared;

use Filament\Widgets\StatsOverviewWidget;

/**
 * Base class for all Autnyx stat/KPI card widgets.
 *
 * Drop this widget onto any page and it will render its cards.
 * Override getStats() in the subclass — that's the only method you need.
 *
 * Usage:
 *   protected function getStats(): array
 *   {
 *       return [
 *           Stat::make('Total Sales', '$12,400')->description('+8% vs last week'),
 *       ];
 *   }
 */
abstract class BaseStatsWidget extends StatsOverviewWidget
{
    /**
     * How often (in seconds) to auto-refresh. null = no polling.
     */
    protected ?string $pollingInterval = null;

    /**
     * Full width by default so stats span the page.
     */
    protected int | string | array $columnSpan = 'full';
}
