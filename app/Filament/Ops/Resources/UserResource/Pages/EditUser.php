<?php

namespace App\Filament\Ops\Resources\UserResource\Pages;

use App\Filament\Ops\Resources\UserResource;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /** Show the current role in the picker. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['role'] = ! empty($data['is_tenant_admin'])
            ? UserResource::ROLE_TENANT_ADMIN
            : UserResource::ROLE_USER;

        return $data;
    }

    /** Map the picker back to the flag; this resource never changes super-admin status. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $role = $data['role'] ?? UserResource::ROLE_USER;
        $data['is_tenant_admin'] = $role === UserResource::ROLE_TENANT_ADMIN;
        unset($data['role']);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
