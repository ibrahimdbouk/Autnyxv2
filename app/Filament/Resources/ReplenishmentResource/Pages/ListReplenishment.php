<?php

namespace App\Filament\Resources\ReplenishmentResource\Pages;

use App\Filament\Resources\ReplenishmentResource;
use Filament\Resources\Pages\ListRecords;

class ListReplenishment extends ListRecords
{
    // v2.5 — the resource's menu set as tabs along the top.
    use \App\Filament\Concerns\ShowsMenuSetTabs;

    protected static string $resource = ReplenishmentResource::class;
}
