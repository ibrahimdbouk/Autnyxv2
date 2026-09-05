<?php

namespace App\Providers\Filament;

use App\Filament\Ops\Pages\TenantsOverview;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * 2a — the super-admin control plane. A second panel at /ops, NOT tenant-scoped
 * (it works across every tenant), gated to super admins by
 * User::canAccessPanel(). Amber accent so it reads as a distinct, elevated
 * context from the violet tenant panel.
 */
class OpsPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('ops')
            ->path('ops')
            ->login()
            ->spa()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->brandName('Autnyx Ops')
            ->renderHook(
                'panels::head.end',
                fn (): string => '<link rel="stylesheet" href="'
                    . asset('css/autnyx-ui.css') . '?v=' . \App\Support\Branding::cssVersion() . '">'
            )
            ->maxContentWidth('full')
            ->discoverResources(in: app_path('Filament/Ops/Resources'), for: 'App\\Filament\\Ops\\Resources')
            ->discoverPages(in: app_path('Filament/Ops/Pages'), for: 'App\\Filament\\Ops\\Pages')
            ->pages([
                TenantsOverview::class,
                \App\Filament\Pages\Account::class,
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
