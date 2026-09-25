<?php

namespace App\Filament\Resources\CustomRuleResource\Pages;

use App\Filament\Resources\CustomRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCustomRule extends EditRecord
{
    protected static string $resource = CustomRuleResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['key'] = $this->record->key;

        return CustomRuleResource::prepare($data);
    }

    protected function getHeaderActions(): array
    {
        return [CustomRuleResource::previewAction()->record($this->record), DeleteAction::make()];
    }
}
