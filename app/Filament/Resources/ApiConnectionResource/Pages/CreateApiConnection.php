<?php

namespace App\Filament\Resources\ApiConnectionResource\Pages;

use App\Filament\Resources\ApiConnectionResource;
use App\Models\ApiConnection;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateApiConnection extends CreateRecord
{
    protected static string $resource = ApiConnectionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = Filament::getTenant()?->id;
        $data['status']    = ApiConnection::STATUS_NEVER;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
