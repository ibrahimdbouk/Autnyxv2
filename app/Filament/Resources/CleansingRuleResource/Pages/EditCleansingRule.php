<?php

namespace App\Filament\Resources\CleansingRuleResource\Pages;

use App\Filament\Resources\CleansingRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCleansingRule extends EditRecord
{
    protected static string $resource = CleansingRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
