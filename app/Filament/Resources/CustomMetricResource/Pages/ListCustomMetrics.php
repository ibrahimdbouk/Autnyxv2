<?php

namespace App\Filament\Resources\CustomMetricResource\Pages;

use App\Filament\Resources\CustomMetricResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCustomMetrics extends ListRecords
{
    protected static string $resource = CustomMetricResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
