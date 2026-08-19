<?php

namespace App\Filament\Resources\SuppressionResource\Pages;

use App\Filament\Resources\SuppressionResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateSuppression extends CreateRecord
{
    protected static string $resource = SuppressionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id']  = Filament::getTenant()?->id;
        $data['created_by'] = auth()->id();
        $data['starts_at']  = $data['starts_at'] ?? now();

        // Default to a 30-day expiry to avoid indefinite suppression.
        if (empty($data['expires_at'])) {
            $data['expires_at'] = now()->addDays(30);
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
