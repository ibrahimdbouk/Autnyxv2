<?php

namespace App\Filament\Dashboards;

use App\Models\Tenant;

/** The Tasks tab of the Dashboard — filled in with the Task Execution build. */
class TasksDashboard implements AppDashboard
{
    public function view(): string
    {
        return 'filament.dashboards.tasks';
    }

    public function data(?Tenant $tenant): array
    {
        return [];
    }
}
