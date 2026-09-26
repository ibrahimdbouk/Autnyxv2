<?php

namespace App\Filament\Resources\RetailEventResource\Pages;

use App\Filament\Resources\RetailEventResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateRetailEvent extends CreateRecord
{
    protected static string $resource = RetailEventResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = Filament::getTenant()?->id;

        return RetailEventResource::prepare($data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
