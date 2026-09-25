<?php

namespace App\Filament\Resources\CustomMetricResource\Pages;

use App\Filament\Resources\CustomMetricResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCustomMetric extends EditRecord
{
    protected static string $resource = CustomMetricResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['key'] = $this->record->key;

        return CustomMetricResource::prepare($data, record: $this->record);
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
