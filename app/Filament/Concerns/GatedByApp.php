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
 * from birth. Root-Cause is universal today (every tenant is entitled), so it is
 * intentionally not retrofitted.
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
        $tenant = Filament::getTenant();

        return $tenant instanceof Tenant ? $tenant->hasApp(static::APP_KEY) : true;
    }
}
