<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\FinancialSummaryWidget;
use App\Filament\Widgets\InventoryHealthChartWidget;
use App\Filament\Widgets\InvestigationsOverviewWidget;
use App\Filament\Widgets\OverviewStatsWidget;
use App\Filament\Widgets\PoFulfillmentStatsWidget;
use App\Filament\Widgets\RecentAnomaliesWidget;
use App\Filament\Widgets\SalesTrendChartWidget;
use App\Filament\Widgets\AnomalyTrendChartWidget;
use App\Filament\Widgets\StoreComparisonWidget;
use App\Filament\Widgets\TopSkusChartWidget;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-home';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?int $navigationSort = -2;

    protected string $view = 'filament.pages.dashboard';

    public function getTitle(): string
    {
        return 'Dashboard';
    }

    public function getWidgets(): array
    {
        return [
            InvestigationsOverviewWidget::class,
            FinancialSummaryWidget::class,
            OverviewStatsWidget::class,
            SalesTrendChartWidget::class,
            TopSkusChartWidget::class,
            InventoryHealthChartWidget::class,
            PoFulfillmentStatsWidget::class,
            RecentAnomaliesWidget::class,
            AnomalyTrendChartWidget::class,
            StoreComparisonWidget::class,
        ];
    }
}
