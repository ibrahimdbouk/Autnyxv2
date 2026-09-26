<?php

namespace App\Filament\Resources\FxRateResource\Pages;

use App\Filament\Resources\FxRateResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateFxRate extends CreateRecord
{
    protected static string $resource = FxRateResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = Filament::getTenant()?->id;
        $data['source'] = 'manual';

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
