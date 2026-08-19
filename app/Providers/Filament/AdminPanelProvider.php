<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
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
use App\Models\Tenant;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->tenant(Tenant::class, slugAttribute: 'slug')
            ->colors([
                'primary' => Color::Violet,
            ])
            ->brandName('Autnyx')
            ->maxContentWidth('full')
            ->databaseNotifications()
            ->databaseNotificationsPolling('60s')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
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
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
