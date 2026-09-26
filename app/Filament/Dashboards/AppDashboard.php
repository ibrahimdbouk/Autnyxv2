<?php

namespace App\Filament\Dashboards;

use App\Models\Tenant;

/**
 * One app's dashboard, shown as a tab of the single Dashboard page. Each app
 * owns its view and its figures; the Dashboard page only picks the tab.
 * A tab never shows another app's numbers.
 */
interface AppDashboard
{
    /** Blade view rendered inside the Dashboard page. */
    public function view(): string;

    /** @return array<string,mixed> data for the view (only computed for the active tab) */
    public function data(?Tenant $tenant): array;
}
