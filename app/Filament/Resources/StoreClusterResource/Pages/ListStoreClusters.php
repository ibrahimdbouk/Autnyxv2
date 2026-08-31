<?php

namespace App\Filament\Resources\StoreClusterResource\Pages;

use App\Filament\Resources\StoreClusterResource;
use App\Models\Store;
use App\Models\StoreCluster;
use App\Platform\Intelligence\Clustering\ClusterService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListStoreClusters extends ListRecords
{
    protected static string $resource = StoreClusterResource::class;

    /** Build the recommended clusters on first visit so the page is never empty. */
    public function mount(): void
    {
        parent::mount();

        $tenant = Filament::getTenant();
        if (! $tenant) {
            return;
        }

        $method = config('clustering.strategy', 'attribute');
        $hasClusters = StoreCluster::query()
            ->where('tenant_id', $tenant->id)
            ->where('method', $method)
            ->exists();

        // Rebuild is always safe now (it re-applies pins), so just ensure the
        // page isn't empty on first visit.
        if (! $hasClusters && Store::where('tenant_id', $tenant->id)->exists()) {
            app(ClusterService::class)->rebuild($tenant->id);
        }
    }

    public function getSubheading(): ?string
    {
        $tenant = Filament::getTenant();
        if (! $tenant) {
            return null;
        }

        $base = app(ClusterService::class)->hasPins($tenant->id)
            ? 'You’ve customised these clusters. Untouched stores still re-cluster automatically and new stores are placed for you; your pinned changes stay put until you reset.'
            : 'Recommended peer groups, by store format and region. Rebuilt automatically as your stores change. Edit any group to make them your own.';

        $unassigned = count(app(ClusterService::class)->unassignedStoreIds($tenant->id));

        return $unassigned > 0
            ? $base . ' ' . $unassigned . ' store(s) are not in any cluster.'
            : $base;
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add cluster'),

            Action::make('reset')
                ->label('Reset to recommended')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn () => ($t = Filament::getTenant()) && app(ClusterService::class)->hasPins($t->id))
                ->requiresConfirmation()
                ->modalHeading('Reset clusters')
                ->modalDescription('Discard your changes and regenerate the recommended clusters from store format and region?')
                ->action(function () {
                    app(ClusterService::class)->resetToRecommended(Filament::getTenant()->id);
                    Notification::make()->title('Clusters reset to the recommended grouping.')->success()->send();
                }),
        ];
    }
}
