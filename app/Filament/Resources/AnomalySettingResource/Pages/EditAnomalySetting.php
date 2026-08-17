<?php

namespace App\Filament\Resources\AnomalySettingResource\Pages;

use App\Filament\Resources\AnomalySettingResource;
use Filament\Resources\Pages\EditRecord;

class EditAnomalySetting extends EditRecord
{
    protected static string $resource = AnomalySettingResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
