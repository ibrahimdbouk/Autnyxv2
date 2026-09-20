<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GatesPageByScreen;
use App\Models\AgentRun;
use App\Services\Agents\DataQualityAgent;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Data Quality (Agent #3) — the AI readiness narration.
 *
 * A distinct surface from the Data Health Center (Feature 4): the Center shows
 * the deterministic health snapshots/scores; this page shows the AI's plain-
 * language readiness verdict + prioritised issues over those checks. It narrates;
 * it never edits the data. (Class name is historically `DataHealth`; it presents
 * as "Data Quality" with its own slug/screen key to avoid colliding with the
 * Center.)
 */
class DataHealth extends Page
{
    use GatesPageByScreen;

    const SCREEN_KEY = 'data_quality';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-sparkles';

    protected static \UnitEnum|string|null $navigationGroup = 'Intelligence';

    protected static ?string $navigationLabel = 'Data Quality';

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'data-quality';

    protected string $view = 'filament.pages.data-health';

    public function getTitle(): string
    {
        return 'Data Quality';
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

        // Best-effort: never let the AgentRun lookup 500 the page.
        try {
            $run = AgentRun::where('tenant_id', $tenantId)
                ->where('agent_key', AgentRun::KEY_DATA_QUALITY)
                ->whereIn('status', [AgentRun::STATUS_COMPLETE, AgentRun::STATUS_FAILED])
                ->latest('id')
                ->first();
        } catch (\Throwable $e) {
            return ['ready' => true, 'has' => false];
        }

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
            'issues'       => is_array($run->out('issues')) ? $run->out('issues') : [],
            'checks'       => is_array($run->out('checks')) ? $run->out('checks') : [],
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

        Notification::make()->title('Data-quality check complete')->success()->send();
    }
}
