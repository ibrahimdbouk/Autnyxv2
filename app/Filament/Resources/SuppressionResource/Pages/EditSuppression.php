<?php

namespace App\Filament\Resources\SuppressionResource\Pages;

use App\Filament\Resources\SuppressionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSuppression extends EditRecord
{
    protected static string $resource = SuppressionResource::class;

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
