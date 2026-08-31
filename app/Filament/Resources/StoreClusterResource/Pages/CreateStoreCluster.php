<?php

namespace App\Filament\Resources\StoreClusterResource\Pages;

use App\Filament\Resources\StoreClusterResource;
use App\Platform\Intelligence\Clustering\ClusterService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateStoreCluster extends CreateRecord
{
    protected static string $resource = StoreClusterResource::class;

    /** Stamp tenant + method + a unique key onto a user-created custom cluster. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = Filament::getTenant()?->id;
        $data['method'] = config('clustering.strategy', 'attribute');
        $data['objective'] = \App\Models\StoreCluster::OBJECTIVE_GENERAL;
        $data['key'] = 'custom-' . Str::slug($data['label'] ?? 'group') . '-' . Str::lower(Str::random(5));
        $data['params'] = null; // custom groups have no attribute definition

        return $data;
    }

    /** Persist the new custom group as pins (survives the nightly rebuild) and keep single-membership. */
    protected function afterCreate(): void
    {
        app(ClusterService::class)->applyManualEdit($this->record);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
