<?php

namespace App\Filament\Resources\SsoConnectionResource\Pages;

use App\Filament\Resources\SsoConnectionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSsoConnections extends ListRecords
{
    protected static string $resource = SsoConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
