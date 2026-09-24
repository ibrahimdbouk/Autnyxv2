<?php

namespace App\Filament\Resources\TenantResource\Pages;

use App\Filament\Resources\TenantResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTenant extends EditRecord
{
    protected static string $resource = TenantResource::class;

    protected function getHeaderActions(): array
    {
        // WP2.4 (audit M9/H7): no raw delete here — it bypassed the protected-
        // tenant check, the typed-name confirmation and the ordered erase. Tenants
        // are removed only through Ops → Tenants → Erase (TenantOffboardingService).
        return [];
    }
}
