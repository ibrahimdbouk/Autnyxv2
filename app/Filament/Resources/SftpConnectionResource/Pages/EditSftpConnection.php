<?php

namespace App\Filament\Resources\SftpConnectionResource\Pages;

use App\Filament\Resources\SftpConnectionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSftpConnection extends EditRecord
{
    protected static string $resource = SftpConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->requiresConfirmation(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
