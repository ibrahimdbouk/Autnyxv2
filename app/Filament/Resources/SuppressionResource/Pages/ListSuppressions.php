<?php

namespace App\Filament\Resources\SuppressionResource\Pages;

use App\Filament\Resources\SuppressionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSuppressions extends ListRecords
{
    // v2.5 — the resource's menu set as tabs along the top.
    use \App\Filament\Concerns\ShowsMenuSetTabs;

    protected static string $resource = SuppressionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
