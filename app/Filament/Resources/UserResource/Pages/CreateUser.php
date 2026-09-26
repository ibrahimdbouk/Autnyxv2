<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\CreateRecord;
use Filament\Facades\Filament;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Automatically assign the new user to the current tenant
        // Super admins creating via a tenant context also assign correctly
        if (!isset($data['tenant_id'])) {
            $data['tenant_id'] = Filament::getTenant()?->id;
        }
        $this->managedNodes = array_key_exists('managed_nodes', $data) ? (array) $data['managed_nodes'] : null;
        unset($data['managed_nodes']);

        return $data;
    }

    /** @var array<int,int>|null platform core: managed regions / areas from the form */
    private ?array $managedNodes = null;

    protected function afterCreate(): void
    {
        UserResource::syncManagedNodes($this->record, $this->managedNodes);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
