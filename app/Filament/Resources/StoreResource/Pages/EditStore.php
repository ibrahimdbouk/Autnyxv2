<?php

namespace App\Filament\Resources\StoreResource\Pages;

use App\Filament\Resources\StoreResource;
use Filament\Resources\Pages\EditRecord;

class EditStore extends EditRecord
{
    protected static string $resource = StoreResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['tenant_id']); // never moved between tenants

        return $data;
    }
}
