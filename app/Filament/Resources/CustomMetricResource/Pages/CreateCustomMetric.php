<?php

namespace App\Filament\Resources\CustomMetricResource\Pages;

use App\Filament\Resources\CustomMetricResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomMetric extends CreateRecord
{
    protected static string $resource = CustomMetricResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = Filament::getTenant()?->id;

        return CustomMetricResource::prepare($data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
