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
            ->spa()
            ->tenant(Tenant::class, slugAttribute: 'slug')
            ->colors([
                'primary' => Color::Violet,
            ])
            ->brandName('Autnyx')
            // Global design tokens + primitives (Track U) — no build step.
            ->renderHook(
                'panels::head.end',
                fn (): string => '<link rel="stylesheet" href="'
                    . asset('css/autnyx-ui.css') . '?v=' . \App\Support\Branding::cssVersion() . '">'
            )
            // 1b — offer SSO from the password login page (break-glass stays here).
            ->renderHook(
                'panels::auth.login.form.after',
                fn (): string => '<div style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--gray-200,#e5e7eb);text-align:center;font-size:.85rem;">'
                    . '<a href="' . url('/sso/start') . '" style="color:var(--primary-600,#6d28d9);font-weight:600;text-decoration:none;">Sign in with single sign-on</a>'
                    . '</div>'
            )
            // 2c — impersonation banner: always-visible while a super admin is
            // acting as a tenant user, with a one-click exit.
            ->renderHook(
                'panels::body.start',
                function (): string {
                    if (! session()->has(\App\Http\Controllers\ImpersonationController::SESSION_KEY)) {
                        return '';
                    }
                    $who = e(auth()->user()?->email ?? 'a tenant user');

                    return '<div style="position:sticky;top:0;z-index:50;background:#b45309;color:#fff;padding:.5rem 1rem;font-size:.85rem;display:flex;align-items:center;justify-content:center;gap:1rem;">'
                        . '<span>You are viewing as <strong>' . $who . '</strong>. Actions are recorded.</span>'
                        . '<a href="' . url('/impersonate/leave') . '" style="color:#fff;font-weight:700;text-decoration:underline;">Leave</a>'
                        . '</div>';
                }
            )
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
