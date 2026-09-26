<?php

namespace App\Filament\Resources\OrgUnitResource\Pages;

use App\Filament\Resources\OrgUnitResource;
use Filament\Resources\Pages\ListRecords;

class ListOrgUnits extends ListRecords
{
    protected static string $resource = OrgUnitResource::class;

    public function getTitle(): string
    {
        return 'Regions & Areas';
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }
}
