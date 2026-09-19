<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GatesPageByScreen;
use App\Models\AgentRun;
use App\Services\Agents\WeeklyBriefingAgent;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Weekly Briefing (Agent #2) — the Monday "state of the business" note.
 *
 * Shows the latest AI briefing for the current tenant and lets a user regenerate
 * it on demand. The briefing narrates deterministic weekly movement; it decides
 * nothing. A scheduled command produces it every Monday.
 */
class WeeklyBriefing extends Page
{
    use GatesPageByScreen;

    const SCREEN_KEY = 'weekly_briefing';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-newspaper';

    protected static \UnitEnum|string|null $navigationGroup = 'Intelligence';

    protected static ?string $navigationLabel = 'Weekly Briefing';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'weekly-briefing';

    protected string $view = 'filament.pages.weekly-briefing';

    public function getTitle(): string
    {
        return 'Weekly Briefing';
    }

    /**
     * @return array<string,mixed>
     */
    public function getBriefing(): array
    {
        $tenantId = Filament::getTenant()?->id;
        if (! $tenantId) {
            return ['ready' => false];
        }

        $run = AgentRun::where('tenant_id', $tenantId)
            ->where('agent_key', AgentRun::KEY_WEEKLY_BRIEFING)
            ->whereIn('status', [AgentRun::STATUS_COMPLETE, AgentRun::STATUS_FAILED])
            ->latest('id')
            ->first();

        if (! $run) {
            return ['ready' => true, 'has' => false];
        }

        return [
            'ready'             => true,
            'has'               => true,
            'failed'            => $run->isFailed(),
            'headline'          => $run->out('headline'),
            'summary'           => $run->out('summary'),
            'whats_new'         => $run->out('whats_new', []),
            'whats_recovering'  => $run->out('whats_recovering', []),
            'where_money_moved' => $run->out('where_money_moved', []),
            'focus_next_week'   => $run->out('focus_next_week', []),
            'stats'             => $run->out('stats', []),
            'confidence'        => $run->confidence,
            'generated_at'      => optional($run->created_at)->format('D, d M Y H:i'),
            'generated_ago'     => optional($run->created_at)->diffForHumans(),
        ];
    }

    /** Regenerate this tenant's briefing on demand. */
    public function generate(): void
    {
        $tenantId = Filament::getTenant()?->id;
        if (! $tenantId) {
            return;
        }

        $run = app(WeeklyBriefingAgent::class)->generate($tenantId, auth()->id());

        if ($run->isFailed()) {
            Notification::make()->title('Could not generate the briefing')
                ->body('The AI service did not respond. Please try again in a moment.')
                ->danger()->send();

            return;
        }

        Notification::make()->title('Briefing updated')->success()->send();
    }
}
