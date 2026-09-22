<?php

namespace App\Filament\Resources\QuarantinedRowResource\Pages;

use App\Filament\Resources\QuarantinedRowResource;
use Filament\Resources\Pages\ListRecords;

class ListQuarantinedRows extends ListRecords
{
    protected static string $resource = QuarantinedRowResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
