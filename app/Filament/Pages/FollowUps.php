<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GatesPageByScreen;
use App\Models\AgentRun;
use App\Services\Agents\ActionFollowUpAgent;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Follow-Ups (Agent #6) — "did the fix work?" across actioned investigations.
 *
 * Lists the latest AI follow-up per actioned investigation, with a link back to
 * the investigation and an escalation flag when a fix hasn't taken. The agent
 * recommends; it never reopens or escalates on its own.
 */
class FollowUps extends Page
{
    use GatesPageByScreen;

    const SCREEN_KEY = 'follow_ups';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static \UnitEnum|string|null $navigationGroup = 'Root Cause';

    protected static ?string $navigationLabel = 'Follow-Ups';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'follow-ups';

    protected string $view = 'filament.pages.follow-ups';

    public function getTitle(): string
    {
        return 'Action Follow-Ups';
    }

    public static function getNavigationBadge(): ?string
    {
        $tenantId = Filament::getTenant()?->id;
        if (! $tenantId) {
            return null;
        }
        // Show how many current follow-ups recommend escalation.
        $n = AgentRun::where('tenant_id', $tenantId)
            ->where('agent_key', AgentRun::KEY_ACTION_FOLLOWUP)
            ->where('status', AgentRun::STATUS_COMPLETE)
            ->get(['output'])
            ->filter(fn ($r) => (bool) data_get($r->output, 'recommend_escalate'))
            ->count();

        return $n > 0 ? (string) $n : null;
    }

    /**
     * @return array<string,mixed>
     */
    public function getFollowUps(): array
    {
        $tenantId = Filament::getTenant()?->id;
        if (! $tenantId) {
            return ['ready' => false];
        }

        $runs = AgentRun::where('tenant_id', $tenantId)
            ->where('agent_key', AgentRun::KEY_ACTION_FOLLOWUP)
            ->where('status', AgentRun::STATUS_COMPLETE)
            ->latest('id')
            ->limit(50)
            ->get();

        $rows = $runs->map(function ($run) {
            $invId = (int) $run->subject_id;

            return [
                'title'    => $run->title,
                'status'   => $run->out('follow_status', 'no_signal'),
                'note'     => $run->out('note'),
                'escalate' => (bool) $run->out('recommend_escalate'),
                'at_risk'  => $run->out('signal.revenue_at_risk'),
                'product'  => $run->out('signal.product'),
                'next'     => $run->out('next_check_days'),
                'url'      => \App\Filament\Resources\InvestigationResource::getUrl('investigate', ['record' => $invId]),
                'ago'      => optional($run->created_at)->diffForHumans(),
            ];
        })->all();

        return [
            'ready'    => true,
            'rows'     => $rows,
            'escalate' => collect($rows)->where('escalate', true)->count(),
        ];
    }

    public function run(): void
    {
        $tenantId = Filament::getTenant()?->id;
        if (! $tenantId) {
            return;
        }

        // WP5.4: rate-limited per person, and run on the queue.
        if (! \Illuminate\Support\Facades\RateLimiter::attempt('ai-button:' . auth()->id(), (int) config('ai.per_user_per_minute', 10), fn () => true, 60)) {
            Notification::make()->title('Too many AI requests — try again in a minute')->warning()->send();

            return;
        }
        \App\Jobs\AI\RunFollowUpsJob::dispatch($tenantId);

        Notification::make()
            ->title('Drafting follow-ups — they appear here in a minute or two')
            ->success()->send();
    }
}
