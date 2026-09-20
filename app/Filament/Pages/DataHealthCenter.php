<?php

namespace App\Filament\Pages;
use App\Filament\Concerns\GatesPageByScreen;

use App\Models\AgentRun;
use App\Models\DataHealthSnapshot;
use App\Services\Agents\DataQualityAgent;
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
            Action::make('aiReview')
                ->label('AI review')
                ->icon('heroicon-o-sparkles')
                ->action(function () {
                    $tenantId = Filament::getTenant()?->id;
                    if (! $tenantId) {
                        return;
                    }
                    $run = app(DataQualityAgent::class)->checkTenant($tenantId, auth()->id());
                    if ($run->isFailed()) {
                        Notification::make()->title('AI review could not run')
                            ->body('The AI service did not respond. Please try again in a moment.')
                            ->danger()->send();

                        return;
                    }
                    Notification::make()->title('AI review updated')->success()->send();
                }),
        ];
    }

    /**
     * Latest AI data-quality narration for this tenant (Agent #3), shaped for the
     * view. Null when none has been run. The AI narrates the deterministic checks;
     * it never alters the data.
     *
     * @return array<string,mixed>|null
     */
    public function getAiReview(): ?array
    {
        $tenantId = Filament::getTenant()?->id;
        if (! $tenantId) {
            return null;
        }

        // Best-effort: the AI panel is optional — never let its lookup 500 the
        // whole Data Health page (e.g. if agent_runs is absent in a given
        // environment/test harness). Fail closed to "no review".
        try {
            $run = AgentRun::where('tenant_id', $tenantId)
                ->where('agent_key', AgentRun::KEY_DATA_QUALITY)
                ->where('status', AgentRun::STATUS_COMPLETE)
                ->latest('id')
                ->first();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[DataHealthCenter] getAiReview failed: ' . $e->getMessage());

            return null;
        }

        if (! $run) {
            return null;
        }

        return [
            'headline'   => $run->out('headline'),
            'summary'    => $run->out('summary'),
            'readiness'  => $run->out('data_readiness', 'fair'),
            'issues'     => is_array($run->out('issues')) ? $run->out('issues') : [],
            'generated'  => optional($run->created_at)->diffForHumans(),
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
