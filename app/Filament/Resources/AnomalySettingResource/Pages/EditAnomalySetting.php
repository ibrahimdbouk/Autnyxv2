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

    /** WP7.4: the form shows the effective values; saving keeps overrides only. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['thresholds'] = $this->record->getEffectiveThresholds();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
