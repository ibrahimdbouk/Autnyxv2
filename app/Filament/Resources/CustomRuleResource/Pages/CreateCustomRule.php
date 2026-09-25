<?php

namespace App\Filament\Resources\CustomRuleResource\Pages;

use App\Filament\Resources\CustomRuleResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomRule extends CreateRecord
{
    protected static string $resource = CustomRuleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = Filament::getTenant()?->id;

        return CustomRuleResource::prepare($data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
