<?php

namespace App\Filament\Resources\CustomMetricResource\Pages;

use App\Filament\Resources\CustomMetricResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCustomMetrics extends ListRecords
{
    // v2.5 — the resource's menu set as tabs along the top.
    use \App\Filament\Concerns\ShowsMenuSetTabs;

    protected static string $resource = CustomMetricResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
