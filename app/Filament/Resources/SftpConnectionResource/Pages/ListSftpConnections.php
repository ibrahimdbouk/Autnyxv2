<?php

namespace App\Filament\Resources\SftpConnectionResource\Pages;

use App\Filament\Resources\SftpConnectionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSftpConnections extends ListRecords
{
    protected static string $resource = SftpConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
