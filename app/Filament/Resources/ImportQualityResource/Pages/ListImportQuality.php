<?php

namespace App\Filament\Resources\ImportQualityResource\Pages;

use App\Filament\Resources\ImportQualityResource;
use Filament\Resources\Pages\ListRecords;

class ListImportQuality extends ListRecords
{
    protected static string $resource = ImportQualityResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
