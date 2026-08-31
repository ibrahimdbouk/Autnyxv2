<?php

namespace App\Filament\Ops\Resources\UserResource\Pages;

use App\Filament\Ops\Resources\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /** Map the role picker to flags; never mint a super admin from here. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $role = $data['role'] ?? UserResource::ROLE_USER;
        $data['is_tenant_admin'] = $role === UserResource::ROLE_TENANT_ADMIN;
        $data['is_super_admin'] = false;
        unset($data['role']);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
