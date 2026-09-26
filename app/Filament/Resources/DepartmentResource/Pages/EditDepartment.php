<?php

namespace App\Filament\Resources\DepartmentResource\Pages;

use App\Filament\Resources\DepartmentResource;
use Filament\Resources\Pages\EditRecord;

class EditDepartment extends EditRecord
{
    protected static string $resource = DepartmentResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['tenant_id'], $data['source']);
        $data['categories'] = array_values(array_filter(array_map('trim', (array) ($data['categories'] ?? [])))) ?: null;
        if (isset($data['parent_id']) && (int) $data['parent_id'] === (int) $this->record->getKey()) {
            $data['parent_id'] = null;
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
