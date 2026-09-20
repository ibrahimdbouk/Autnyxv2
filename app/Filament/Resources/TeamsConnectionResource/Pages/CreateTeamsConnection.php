<?php

namespace App\Filament\Resources\TeamsConnectionResource\Pages;

use App\Filament\Resources\TeamsConnectionResource;
use App\Models\TeamsConnection;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateTeamsConnection extends CreateRecord
{
    protected static string $resource = TeamsConnectionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = Filament::getTenant()?->id;
        $data['status']    = TeamsConnection::STATUS_NEVER;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
