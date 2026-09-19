<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GatesPageByScreen;
use App\Models\AgentRun;
use App\Services\Agents\DataQualityAgent;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Data Health (Agent #3) — data-quality / onboarding readiness.
 *
 * Shows the latest AI data-quality check for the current tenant and lets a user
 * run it on demand. It narrates deterministic completeness/freshness checks; it
 * never edits the underlying data.
 */
class DataHealth extends Page
{
    use GatesPageByScreen;

    const SCREEN_KEY = 'data_health';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-shield-check';

    protected static \UnitEnum|string|null $navigationGroup = 'Intelligence';

    protected static ?string $navigationLabel = 'Data Health';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'data-health';

    protected string $view = 'filament.pages.data-health';

    public function getTitle(): string
    {
        return 'Data Health';
    }

    /**
     * @return array<string,mixed>
     */
    public function getReport(): array
    {
        $tenantId = Filament::getTenant()?->id;
        if (! $tenantId) {
            return ['ready' => false];
        }

        $run = AgentRun::where('tenant_id', $tenantId)
            ->where('agent_key', AgentRun::KEY_DATA_QUALITY)
            ->whereIn('status', [AgentRun::STATUS_COMPLETE, AgentRun::STATUS_FAILED])
            ->latest('id')
            ->first();

        if (! $run) {
            return ['ready' => true, 'has' => false];
        }

        return [
            'ready'        => true,
            'has'          => true,
            'failed'       => $run->isFailed(),
            'headline'     => $run->out('headline'),
            'summary'      => $run->out('summary'),
            'readiness'    => $run->out('data_readiness', 'fair'),
            'issues'       => $run->out('issues', []),
            'checks'       => $run->out('checks', []),
            'confidence'   => $run->confidence,
            'generated_ago'=> optional($run->created_at)->diffForHumans(),
            'generated_at' => optional($run->created_at)->format('D, d M Y H:i'),
        ];
    }

    public function run(): void
    {
        $tenantId = Filament::getTenant()?->id;
        if (! $tenantId) {
            return;
        }

        $run = app(DataQualityAgent::class)->checkTenant($tenantId, auth()->id());

        if ($run->isFailed()) {
            Notification::make()->title('Could not run the data-quality check')
                ->body('The AI service did not respond. Please try again in a moment.')
                ->danger()->send();

            return;
        }

        Notification::make()->title('Data health check complete')->success()->send();
    }
}
