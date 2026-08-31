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
                ->after(fn () => app(ClusterService::class)->markCustomised(Filament::getTenant()?->id ?? 0)),
        ];
    }

    /** A store lives in one cluster: pull its stores out of any other group, mark customised. */
    protected function afterSave(): void
    {
        app(ClusterService::class)->enforceSingleMembership($this->record);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
