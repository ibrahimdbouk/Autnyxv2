<?php

namespace App\Filament\Ops\Resources\TenantResource\Pages;

use App\Filament\Ops\Resources\TenantResource;
use App\Models\Tenant;
use App\Services\Ops\TenantProvisioner;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;

    /** Route creation through the provisioner so the first admin is created too. */
    protected function handleRecordCreation(array $data): Model
    {
        return app(TenantProvisioner::class)->create(
            [
                'name'     => $data['name'],
                'slug'     => $data['slug'] ?? null,
                'plan'     => $data['plan'] ?? Tenant::PLAN_TRIAL,
                'status'   => $data['status'] ?? Tenant::STATUS_ACTIVE,
                'apps'     => $data['apps'] ?? Tenant::DEFAULT_APPS,
                'currency' => $data['currency'] ?? 'USD',
            ],
            [
                'name'     => $data['admin_name'] ?? null,
                'email'    => $data['admin_email'] ?? null,
                'password' => $data['admin_password'] ?? null,
            ],
        );
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
