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

    // WP7.4 (D12): the one status screen for data — readiness, dataset health
    // and the AI check — at the top of the Data Quality group (quarantine,
    // cleansing rules, aliases). Data Readiness and the AI "Data Quality"
    // page were folded in; their old URLs redirect here.
    protected static \UnitEnum|string|null $navigationGroup = 'Data Quality';

    protected static ?string $navigationLabel = 'Data Health';

    protected static ?int $navigationSort = 0;

    protected static ?string $slug = 'data-health';

    protected string $view = 'filament.pages.data-health-center';

    public function getTitle(): string
    {
        return 'Data Health';
    }

    /** Open to the data-health screen, or to the AI check's screen alone. */
    protected static function userCanSeeScreen(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->canSeeScreen(self::SCREEN_KEY) || $user?->canSeeScreen('data_quality'));
    }

    public function canSeeDatasets(): bool
    {
        return auth()->user()?->canSeeScreen(self::SCREEN_KEY) ?? false;
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
                        // WP6.4: on the queue — never ~8 full scans per dataset in the request.
                        \App\Jobs\DataHealth\ComputeDataHealthJob::dispatch($tenantId);
                        Notification::make()->title('Recomputing data health')
                            ->body('The figures refresh in a minute or two — reload the page to see them.')->success()->send();
                    }
                }),
        ];
    }

    public function mount(): void
    {
        // Compute on first visit if there are no snapshots yet.
        $tenantId = Filament::getTenant()?->id;
        if ($tenantId && DataHealthSnapshot::where('tenant_id', $tenantId)->doesntExist()) {
            \App\Jobs\DataHealth\ComputeDataHealthJob::dispatch($tenantId);   // WP6.4: queued
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

    /** Readiness is the ingestion firewall's view: admins only (as the old page). */
    public function canSeeReadiness(): bool
    {
        $user = auth()->user();

        return (bool) ($user && ($user->is_super_admin || $user->is_tenant_admin));
    }

    /** @return array{overall:string, datasets:array<int,array<string,mixed>>} */
    public function getReadiness(): array
    {
        $tenantId = Filament::getTenant()?->id;

        return $tenantId && $this->canSeeReadiness()
            ? app(\App\Services\DataQuality\DataReadinessService::class)->summary($tenantId)
            : ['overall' => 'green', 'datasets' => []];
    }

    public function batchesUrl(): ?string
    {
        return \App\Filament\Resources\ImportQualityResource::canViewAny()
            ? \App\Filament\Resources\ImportQualityResource::getUrl('index') : null;
    }

    /** The AI check keeps its own screen permission (data_quality). */
    public function canRunAiCheck(): bool
    {
        return auth()->user()?->canSeeScreen('data_quality') ?? false;
    }

    /** @return array<string,mixed> the latest AI data-quality verdict */
    public function getReport(): array
    {
        $tenantId = Filament::getTenant()?->id;
        if (! $tenantId || ! $this->canRunAiCheck()) {
            return ['ready' => false];
        }

        try {
            $run = \App\Models\AgentRun::where('tenant_id', $tenantId)
                ->where('agent_key', \App\Models\AgentRun::KEY_DATA_QUALITY)
                ->whereIn('status', [\App\Models\AgentRun::STATUS_COMPLETE, \App\Models\AgentRun::STATUS_FAILED])
                ->latest('id')
                ->first();
        } catch (\Throwable $e) {
            report($e);

            return ['ready' => true, 'has' => false];
        }

        if (! $run) {
            return ['ready' => true, 'has' => false];
        }

        return [
            'ready'         => true,
            'has'           => true,
            'failed'        => $run->isFailed(),
            'headline'      => $run->out('headline'),
            'summary'       => $run->out('summary'),
            'readiness'     => $run->out('data_readiness', 'fair'),
            'issues'        => is_array($run->out('issues')) ? $run->out('issues') : [],
            'checks'        => is_array($run->out('checks')) ? $run->out('checks') : [],
            'confidence'    => $run->confidence,
            'generated_ago' => optional($run->created_at)->diffForHumans(),
            'generated_at'  => \App\Support\Tenancy\TenantClock::display($run->created_at)?->format('D, d M Y H:i'),
        ];
    }

    public function run(): void
    {
        $tenantId = Filament::getTenant()?->id;
        if (! $tenantId || ! $this->canRunAiCheck()) {
            return;
        }

        $run = app(\App\Services\Agents\DataQualityAgent::class)->checkTenant($tenantId, auth()->id());

        if ($run->isFailed()) {
            Notification::make()->title('Could not run the data-quality check')
                ->body('The AI service did not respond. Please try again in a moment.')
                ->danger()->send();

            return;
        }

        Notification::make()->title('Data-quality check complete')->success()->send();
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
