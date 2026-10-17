<?php

namespace App\Filament\Resources\AnomalySettingResource\Pages;

use App\Filament\Resources\AnomalySettingResource;
use Filament\Resources\Pages\ListRecords;

class ListAnomalySettings extends ListRecords
{
    // v2.5 — the resource's menu set as tabs along the top.
    use \App\Filament\Concerns\ShowsMenuSetTabs;

    protected static string $resource = AnomalySettingResource::class;
}
