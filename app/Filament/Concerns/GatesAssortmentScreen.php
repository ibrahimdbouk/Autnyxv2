<?php

namespace App\Filament\Concerns;

use App\Models\Tenant;
use Filament\Facades\Filament;

/**
 * Gate an Assortment page: the tenant must hold the Assortment app, and the
 * person must be allowed the screen (SCREEN_KEY) — or be a tenant admin when
 * the page declares ADMIN_ONLY. Pages declare `const APP_KEY = Tenant::APP_ASSORTMENT`
 * so AppGate places them in the Assortment menu group and EnsureAppEntitlement
 * refuses them for tenants without the app.
 */
trait GatesAssortmentScreen
{
    public static function canAccess(): bool
    {
        return static::assortmentAllowed();
    }

    protected static function assortmentAllowed(): bool
    {
        $tenant = Filament::getTenant();
        $user   = auth()->user();
        if (! $tenant instanceof Tenant || ! $user || ! $tenant->hasApp(Tenant::APP_ASSORTMENT)) {
            return false;
        }
        if (defined(static::class . '::ADMIN_ONLY') && constant(static::class . '::ADMIN_ONLY')) {
            return $user->canManageUsers();
        }

        return ! defined(static::class . '::SCREEN_KEY') || $user->canSeeScreen(constant(static::class . '::SCREEN_KEY'));
    }
}
