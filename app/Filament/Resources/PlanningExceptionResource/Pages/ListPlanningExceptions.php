<?php

namespace App\Filament\Resources\PlanningExceptionResource\Pages;

use App\Filament\Resources\PlanningExceptionResource;
use Filament\Resources\Pages\ListRecords;

class ListPlanningExceptions extends ListRecords
{
    protected static string $resource = PlanningExceptionResource::class;

    // Read-only: no create action — rows arrive only from the F&R exception feed.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
