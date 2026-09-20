<?php

namespace App\Filament\Resources\TeamsConnectionResource\Pages;

use App\Filament\Resources\TeamsConnectionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTeamsConnections extends ListRecords
{
    protected static string $resource = TeamsConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
