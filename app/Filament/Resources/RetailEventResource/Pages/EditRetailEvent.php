<?php

namespace App\Filament\Resources\RetailEventResource\Pages;

use App\Filament\Resources\RetailEventResource;
use Filament\Resources\Pages\EditRecord;

class EditRetailEvent extends EditRecord
{
    protected static string $resource = RetailEventResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return RetailEventResource::prepare($data, $this->record);
    }
}
