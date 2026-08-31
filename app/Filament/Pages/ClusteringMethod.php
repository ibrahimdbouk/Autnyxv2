<?php

namespace App\Filament\Pages;

use App\Filament\Resources\StoreClusterResource;
use App\Platform\Intelligence\Clustering\ClusterService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Phase 2 — preview behavioural (demand) vs structural (attribute) clustering
 * side by side, see how the behavioural pass regroups look-alike stores, and
 * switch the tenant between the two. Admin-only; reached from Store Clustering.
 *
 * The comparison is computed in memory (strategies are pure) — nothing is
 * persisted until you actually switch.
 */
class ClusteringMethod extends Page
{
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $slug = 'clustering-method';

    protected string $view = 'filament.pages.clustering-method';

    /** Reached from the Store Clustering page, not the nav. */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->is_tenant_admin || $user?->is_super_admin);
    }

    public function getTitle(): string
    {
        return 'Clustering method';
    }

    private ?array $comparisonCache = null;

    public function comparison(): array
    {
        return $this->comparisonCache ??= app(ClusterService::class)->compare(Filament::getTenant()->id);
    }

    public function money(float|int|null $amount): string
    {
        if ($amount === null) {
            return '—';
        }

        return Filament::getTenant()?->money((float) $amount) ?? number_format((float) $amount, 2);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('use_behavioural')
                ->label('Use behavioural clustering')
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                ->visible(fn () => $this->comparison()['active'] !== 'demand' && $this->comparison()['demand_available'])
                ->requiresConfirmation()
                ->modalHeading('Switch to behavioural clustering?')
                ->modalDescription('Your store clusters will be regrouped by how stores actually trade. This resets any manual cluster edits.')
                ->action(function () {
                    $tenant = Filament::getTenant();
                    app(ClusterService::class)->setActiveMethod($tenant->id, 'demand');
                    app(ClusterService::class)->rebuild($tenant->id, 'demand');
                    $this->comparisonCache = null;
                    Notification::make()->title('Behavioural clustering activated')->success()->send();
                }),

            Action::make('use_structural')
                ->label('Use structural clustering')
                ->icon('heroicon-o-squares-2x2')
                ->color('gray')
                ->visible(fn () => $this->comparison()['active'] === 'demand')
                ->requiresConfirmation()
                ->modalHeading('Switch to structural clustering?')
                ->modalDescription('Your store clusters will be regrouped by format and region. This resets any manual cluster edits.')
                ->action(function () {
                    $tenant = Filament::getTenant();
                    app(ClusterService::class)->setActiveMethod($tenant->id, 'attribute');
                    app(ClusterService::class)->rebuild($tenant->id, 'attribute');
                    $this->comparisonCache = null;
                    Notification::make()->title('Structural clustering activated')->success()->send();
                }),

            Action::make('back')
                ->label('Store clustering')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(StoreClusterResource::getUrl('index')),
        ];
    }
}
