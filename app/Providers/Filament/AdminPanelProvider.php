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
use Filament\Navigation\NavigationGroup;
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
            // Logo slot: appears in the top bar + sign-in page as soon as a file
            // exists at public/images/autnyx-logo.*; falls back to brand name.
            ->brandLogo(fn (): ?string => \App\Support\Branding::logoUrl())
            ->brandLogoHeight('1.75rem')
            // Global design tokens + primitives (Track U) — no build step.
            ->renderHook(
                'panels::head.end',
                fn (): string => '<link rel="stylesheet" href="'
                    . asset('css/autnyx-ui.css') . '?v=' . \App\Support\Branding::cssVersion() . '">'
            )
            ->maxContentWidth('full')
            // Coherent left-nav story: the intelligence the product produces first,
            // then the data behind it, then admin + settings.
            ->navigationGroups([
                NavigationGroup::make('Intelligence')->icon('heroicon-o-sparkles'),
                NavigationGroup::make('Data')->icon('heroicon-o-circle-stack'),
                NavigationGroup::make('Administration')->icon('heroicon-o-building-office'),
                NavigationGroup::make('Settings')->icon('heroicon-o-cog-6-tooth'),
            ])
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
