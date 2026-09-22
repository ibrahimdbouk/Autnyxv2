<?php

namespace App\Filament\Resources\EntityAliasResource\Pages;

use App\Filament\Resources\EntityAliasResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEntityAlias extends EditRecord
{
    protected static string $resource = EntityAliasResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['alias'] = mb_strtolower(trim((string) ($data['alias'] ?? '')));

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
