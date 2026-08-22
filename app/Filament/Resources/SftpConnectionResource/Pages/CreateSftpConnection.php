<?php

namespace App\Filament\Resources\SftpConnectionResource\Pages;

use App\Filament\Resources\SftpConnectionResource;
use App\Models\SftpConnection;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateSftpConnection extends CreateRecord
{
    protected static string $resource = SftpConnectionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = Filament::getTenant()?->id;
        $data['status']    = SftpConnection::STATUS_NEVER;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
