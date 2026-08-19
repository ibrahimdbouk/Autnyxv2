<?php

namespace App\Filament\Resources\SuppressionResource\Pages;

use App\Filament\Resources\SuppressionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSuppressions extends ListRecords
{
    protected static string $resource = SuppressionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
