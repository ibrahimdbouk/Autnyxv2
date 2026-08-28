<?php

namespace App\Filament\Resources\SsoConnectionResource\Pages;

use App\Filament\Resources\SsoConnectionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSsoConnection extends EditRecord
{
    protected static string $resource = SsoConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
