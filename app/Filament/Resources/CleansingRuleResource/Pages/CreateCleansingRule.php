<?php

namespace App\Filament\Resources\CleansingRuleResource\Pages;

use App\Filament\Resources\CleansingRuleResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateCleansingRule extends CreateRecord
{
    protected static string $resource = CleansingRuleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = Filament::getTenant()?->id;

        return $data;
    }
}
