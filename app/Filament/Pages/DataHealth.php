<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GatesPageByScreen;
use App\Models\AgentRun;
use App\Services\Agents\DataQualityAgent;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * DEPRECATED — superseded by DataHealthCenter (Feature 4), which now hosts the AI
 * data-quality review (Agent #3) via its "AI review" action + panel. This page
 * duplicated the Center's nav label / slug / screen key and caused a duplicate
 * "Data Health" entry, so it is hidden from navigation and given a non-colliding
 * slug. Kept only because the file can't be deleted from here while device_bash
 * is unavailable — remove the file (and data-health.blade.php) in a later pass.
 */
class DataHealth extends Page
{
    use GatesPageByScreen;

    const SCREEN_KEY = 'data_health';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-shield-check';

    protected static \UnitEnum|string|null $navigationGroup = 'Intelligence';

    protected static ?string $navigationLabel = 'Data Health (legacy)';

    protected static ?int $navigationSort = 4;

    // Non-colliding slug so it no longer clashes with DataHealthCenter's 'data-health'.
    protected static ?string $slug = 'data-health-ai-legacy';

    protected string $view = 'filament.pages.data-health';

    /** Hidden — the Data Health Center is the single Data Health surface now. */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

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
