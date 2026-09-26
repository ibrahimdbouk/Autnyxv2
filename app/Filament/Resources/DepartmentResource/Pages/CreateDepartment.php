<?php

namespace App\Filament\Resources\DepartmentResource\Pages;

use App\Filament\Resources\DepartmentResource;
use App\Models\Department;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateDepartment extends CreateRecord
{
    protected static string $resource = DepartmentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = Filament::getTenant()?->id;
        $data['source'] = Department::SOURCE_MANUAL;
        $data['categories'] = array_values(array_filter(array_map('trim', (array) ($data['categories'] ?? [])))) ?: null;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
