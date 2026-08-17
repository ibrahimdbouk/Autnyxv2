<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\InventoryHealthChartWidget;
use App\Filament\Widgets\OverviewStatsWidget;
use App\Filament\Widgets\PoFulfillmentStatsWidget;
use App\Filament\Widgets\RecentAnomaliesWidget;
use App\Filament\Widgets\SalesTrendChartWidget;
use App\Filament\Widgets\TopSkusChartWidget;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-home';

    protected static \UnitEnum|string|null $navigationLabel = 'Dashboard';

    protected static ?int $navigationSort = -2;

    public function getWidgets(): array
    {
        return [
            OverviewStatsWidget::class,
            SalesTrendChartWidget::class,
            TopSkusChartWidget::class,
            InventoryHealthChartWidget::class,
            PoFulfillmentStatsWidget::class,
            RecentAnomaliesWidget::class,
        ];
    }

    public function getColumns(): int | string | array
    {
        return 2;
    }
}
