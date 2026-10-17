<?php

namespace App\Filament\Resources\CustomRuleResource\Pages;

use App\Filament\Resources\CustomRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCustomRules extends ListRecords
{
    // v2.5 — the resource's menu set as tabs along the top.
    use \App\Filament\Concerns\ShowsMenuSetTabs;

    protected static string $resource = CustomRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
