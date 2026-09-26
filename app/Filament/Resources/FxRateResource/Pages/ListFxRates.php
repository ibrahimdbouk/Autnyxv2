<?php

namespace App\Filament\Resources\FxRateResource\Pages;

use App\Filament\Resources\FxRateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFxRates extends ListRecords
{
    protected static string $resource = FxRateResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
