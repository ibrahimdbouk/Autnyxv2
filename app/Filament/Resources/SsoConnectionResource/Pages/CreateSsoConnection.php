<?php

namespace App\Filament\Resources\SsoConnectionResource\Pages;

use App\Filament\Resources\SsoConnectionResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateSsoConnection extends CreateRecord
{
    protected static string $resource = SsoConnectionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (! isset($data['tenant_id'])) {
            $data['tenant_id'] = Filament::getTenant()?->id;
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
