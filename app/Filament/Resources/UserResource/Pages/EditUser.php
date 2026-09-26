<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn () => UserResource::canDelete($this->record)),
        ];
    }

    /** @var array<int,int>|null platform core: managed regions / areas from the form */
    private ?array $managedNodes = null;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['managed_nodes'] = $this->record->managedNodes()->pluck('location_nodes.id')->all();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->managedNodes = array_key_exists('managed_nodes', $data) ? (array) $data['managed_nodes'] : null;
        unset($data['managed_nodes']);

        return $data;
    }

    protected function afterSave(): void
    {
        UserResource::syncManagedNodes($this->record, $this->managedNodes);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
