<?php

namespace App\Filament\Concerns;

use App\Models\Tenant;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

/**
 * Gate a Filament Resource/Page behind a per-tenant APP entitlement.
 *
 * The consuming class declares `const APP_KEY = Tenant::APP_ASSORTMENT;`. Its
 * navigation entry and views then appear only for tenants that hold the app
 * (assigned in /ops). This is the enforcement side of app entitlements: new apps
 * (Assortment, Task Execution) use this trait so their nav is entitlement-gated
 * from birth. WP7.4: Root-Cause screens are gated too (AppGate::ROOT_CAUSE), and
 * EnsureAppEntitlement refuses any request to a screen of an app not held.
 *
 * Fail-open only when there is no tenant context (e.g. the super-admin /ops
 * panel, which has its own gating); inside a tenant panel the entitlement decides.
 */
trait GatedByApp
{
    public static function canViewAny(): bool
    {
        return static::tenantHasApp();
    }

    public static function canView(Model $record): bool
    {
        return static::tenantHasApp();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::tenantHasApp();
    }

    protected static function tenantHasApp(): bool
    {
        return \App\Support\Apps\AppGate::allows(Filament::getTenant(), static::class);
    }
}
