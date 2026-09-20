<?php

namespace App\Filament\Pages;
use App\Filament\Concerns\GatesPageByScreen;

use App\Models\DataHealthSnapshot;
use App\Services\DataHealth\DataHealthService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * Data Health Center — Feature 4
 *
 * A single trustworthy summary of whether this tenant's source data is fresh,
 * complete, valid and consistent enough to trust its investigations. All numbers
 * are deterministic (DataHealthService); this page only presents them.
 */
class DataHealthCenter extends Page
{
    use GatesPageByScreen;

    const SCREEN_KEY = 'data_health';
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-heart';

    protected static \UnitEnum|string|null $navigationGroup = 'Intelligence';

    protected static ?string $navigationLabel = 'Data Health';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'data-health';

    protected string $view = 'filament.pages.data-health-center';

    public function getTitle(): string
    {
        return 'Data Health Center';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Recompute')
                ->icon('heroicon-o-arrow-path')
                ->action(function () {
                    $tenantId = Filament::getTenant()?->id;
                    if ($tenantId) {
                        app(DataHealthService::class)->computeForTenant($tenantId);
                        Notification::make()->title('Data health recomputed')->success()->send();
                    }
                }),
        ];
    }

    public function mount(): void
    {
        // Compute on first visit if there are no snapshots yet.
        $tenantId = Filament::getTenant()?->id;
        if ($tenantId && DataHealthSnapshot::where('tenant_id', $tenantId)->doesntExist()) {
            try {
                app(DataHealthService::class)->computeForTenant($tenantId);
            } catch (\Throwable $e) {
                // Non-fatal — page still renders the (empty) state.
            }
        }
    }

    public function getSnapshots(): Collection
    {
        $tenantId = Filament::getTenant()?->id;
        if (! $tenantId) {
            return collect();
        }

        $order = array_keys(DataHealthSnapshot::DATASET_LABELS);

        return DataHealthSnapshot::where('tenant_id', $tenantId)
            ->get()
            ->sortBy(fn ($s) => array_search($s->dataset, $order))
            ->values();
    }

    public function getOverall(): array
    {
        $tenantId = Filament::getTenant()?->id;
        if (! $tenantId) {
            return ['status' => 'no_data', 'score' => null, 'datasets' => 0, 'warning_count' => 0, 'last_computed' => null];
        }
        return app(DataHealthService::class)->overall($tenantId);
    }

    /**
     * @return Collection<int,\App\Models\Investigation>
     */
    public function affectedInvestigations(string $dataset): Collection
    {
        $tenantId = Filament::getTenant()?->id;
        if (! $tenantId) {
            return collect();
        }
        return app(DataHealthService::class)->affectedInvestigations($tenantId, $dataset, 10);
    }
}
