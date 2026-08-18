<?php

namespace App\Filament\Resources\InvestigationResource\Pages;

use App\Filament\Resources\InvestigationResource;
use Filament\Resources\Pages\ListRecords;

class ListInvestigations extends ListRecords
{
    protected static string $resource = InvestigationResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
