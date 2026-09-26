<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GatesPageByScreen;
use App\Models\AgentRun;
use App\Services\Agents\DailyBriefingAgent;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Daily Briefing — the start-of-day note. Shows the latest AI briefing for the
 * current tenant and lets a user regenerate it on demand. It narrates
 * deterministic daily movement; it decides nothing. A scheduled command produces
 * it each morning.
 */
class DailyBriefing extends Page
{
    use GatesPageByScreen;

    const SCREEN_KEY = 'daily_briefing';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-sun';

    protected static \UnitEnum|string|null $navigationGroup = 'Root Cause';

    protected static ?string $navigationLabel = 'Daily Briefing';

    protected static ?int $navigationSort = 0;

    protected static ?string $slug = 'daily-briefing';

    protected string $view = 'filament.pages.daily-briefing';

    public function getTitle(): string
    {
        return 'Daily Briefing';
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

        $latest = AgentRun::where('tenant_id', $tenantId)
            ->where('agent_key', AgentRun::KEY_DAILY_BRIEFING)
            ->whereIn('status', [AgentRun::STATUS_COMPLETE, AgentRun::STATUS_FAILED])
            ->latest('id')
            ->first();

        if (! $latest) {
            return ['ready' => true, 'has' => false];
        }

        // WP5.4: a failed attempt never hides the last good briefing — show it,
        // with a note that the newest attempt failed.
        $run = $latest->isFailed()
            ? (AgentRun::where('tenant_id', $tenantId)->where('agent_key', AgentRun::KEY_DAILY_BRIEFING)
                ->where('status', AgentRun::STATUS_COMPLETE)->latest('id')->first() ?? $latest)
            : $latest;

        return [
            'ready'         => true,
            'has'           => true,
            'failed'        => $latest->isFailed(),
            'last_good_ago' => $latest->isFailed() && ! $run->isFailed() ? optional($run->created_at)->diffForHumans() : null,
            'headline'      => $run->out('headline'),
            'summary'       => $run->out('summary'),
            'overnight'     => $run->out('overnight', []),
            'act_today'     => $run->out('act_today', []),
            'watch'         => $run->out('watch', []),
            'stats'         => $run->out('stats', []),
            'confidence'    => $run->confidence,
            'generated_at'  => \App\Support\Tenancy\TenantClock::display($run->created_at)?->format('D, d M Y H:i'),
            'generated_ago' => optional($run->created_at)->diffForHumans(),
        ];
    }

    /** Regenerate this tenant's briefing on demand. */
    public function generate(): void
    {
        $tenantId = Filament::getTenant()?->id;
        if (! $tenantId) {
            return;
        }

        $run = app(DailyBriefingAgent::class)->generate($tenantId, auth()->id());

        if ($run->isFailed()) {
            Notification::make()->title('Could not generate the briefing')
                ->body('The AI service did not respond. Please try again in a moment.')
                ->danger()->send();

            return;
        }

        Notification::make()->title('Briefing updated')->success()->send();
    }
}
