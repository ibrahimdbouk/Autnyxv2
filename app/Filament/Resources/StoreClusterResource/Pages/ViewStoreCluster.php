<?php

namespace App\Filament\Resources\StoreClusterResource\Pages;

use App\Filament\Concerns\ResolvesRecordKey;
use App\Filament\Resources\StoreClusterResource;
use App\Models\StoreCluster;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\Page;

/**
 * Cluster drill-down: the cluster's numbers and "why", and its member stores each
 * with their behavioural profile. This is where the store feature layer and the
 * clustering explanation become visible — "why is this a group, and why is this
 * store in it".
 */
class ViewStoreCluster extends Page
{
    use ResolvesRecordKey;

    protected static string $resource = StoreClusterResource::class;

    protected string $view = 'filament.resources.store-cluster-resource.pages.view-store-cluster';

    public StoreCluster $record;

    public function mount(int|string $record): void
    {
        $record = $this->resolveRecordKey($record);

        $this->record = StoreCluster::with([
            'stores' => fn ($q) => $q->with('feature')->orderBy('name'),
        ])->findOrFail($record);

        abort_unless($this->record->tenant_id === Filament::getTenant()?->id, 403);
    }

    public function getTitle(): string
    {
        return $this->record->label;
    }

    /** Cluster-level 90-day numbers. */
    public function metrics(): array
    {
        return $this->record->metrics(90);
    }

    /** Format an amount in the current tenant's currency. */
    public function money(float|int|null $amount): string
    {
        if ($amount === null) {
            return '—';
        }

        return Filament::getTenant()?->displayMoney((float) $amount) ?? number_format((float) $amount, 2);
    }

    /** Table cells keep the ISO code. */
    public function tableMoney(float|int|null $amount): string
    {
        if ($amount === null) {
            return '—';
        }

        return Filament::getTenant()?->money((float) $amount) ?? number_format((float) $amount, 2);
    }

    /** v2.5 — this cluster on the map among the others, and its profile against the average store. */
    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\Widgets\Clustering\ClusterMap::make(['focus' => $this->record->id]),
            \App\Filament\Widgets\Clustering\ClusterScatter::make(['focus' => $this->record->id]),
            \App\Filament\Widgets\Clustering\ClusterProfile::make(['focus' => $this->record->id]),
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return ['default' => 1, 'lg' => 3];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('edit')
                ->label('Edit cluster')
                ->icon('heroicon-o-pencil-square')
                ->visible(fn () => StoreClusterResource::canEdit($this->record))
                ->url(fn () => StoreClusterResource::getUrl('edit', ['record' => $this->record])),

            Action::make('back')
                ->label('All clusters')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(StoreClusterResource::getUrl('index')),
        ];
    }
}
