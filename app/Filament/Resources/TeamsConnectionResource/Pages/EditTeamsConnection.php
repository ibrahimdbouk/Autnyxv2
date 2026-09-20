<?php

namespace App\Filament\Resources\TeamsConnectionResource\Pages;

use App\Filament\Resources\TeamsConnectionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTeamsConnection extends EditRecord
{
    protected static string $resource = TeamsConnectionResource::class;

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
