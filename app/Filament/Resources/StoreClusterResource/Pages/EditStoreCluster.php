<?php

namespace App\Filament\Resources\StoreClusterResource\Pages;

use App\Filament\Resources\StoreClusterResource;
use App\Platform\Intelligence\Clustering\ClusterService;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;

class EditStoreCluster extends EditRecord
{
    protected static string $resource = StoreClusterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->after(fn () => app(ClusterService::class)->removeClusterPins(
                    $this->record->tenant_id,
                    $this->record->objective ?? \App\Models\StoreCluster::OBJECTIVE_GENERAL,
                    $this->record->key,
                )),
        ];
    }

    /** Persist the edit as pins (survives the nightly rebuild) and keep single-membership. */
    protected function afterSave(): void
    {
        app(ClusterService::class)->applyManualEdit($this->record);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
