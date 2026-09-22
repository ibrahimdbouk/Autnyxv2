<?php

namespace App\Filament\Resources\CleansingRuleResource\Pages;

use App\Filament\Resources\CleansingRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCleansingRules extends ListRecords
{
    protected static string $resource = CleansingRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
