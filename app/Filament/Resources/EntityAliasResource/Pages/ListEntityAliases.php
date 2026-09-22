<?php

namespace App\Filament\Resources\EntityAliasResource\Pages;

use App\Filament\Resources\EntityAliasResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEntityAliases extends ListRecords
{
    protected static string $resource = EntityAliasResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
