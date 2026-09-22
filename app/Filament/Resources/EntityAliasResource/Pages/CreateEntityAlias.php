<?php

namespace App\Filament\Resources\EntityAliasResource\Pages;

use App\Filament\Resources\EntityAliasResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateEntityAlias extends CreateRecord
{
    protected static string $resource = EntityAliasResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = Filament::getTenant()?->id;
        $data['alias'] = mb_strtolower(trim((string) ($data['alias'] ?? '')));

        return $data;
    }
}
