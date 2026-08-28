<?php

namespace App\Filament\Pages;
use App\Filament\Concerns\GatesPageByScreen;

use App\Models\Investigation;
use App\Services\Watch\WatchEvaluationService;
use App\Services\Watch\WatchService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * My Watched Investigations — Feature 5
 */
class WatchedInvestigations extends Page
{
    use GatesPageByScreen;

    const SCREEN_KEY = 'watched';
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-eye';

    protected static \UnitEnum|string|null $navigationGroup = 'Intelligence';

    protected static ?string $navigationLabel = 'My Watched';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'watched-investigations';

    protected string $view = 'filament.pages.watched-investigations';

    public function getTitle(): string
    {
        return 'My Watched Investigations';
    }

    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();
        $tenantId = Filament::getTenant()?->id;
        if (! $user || ! $tenantId) {
            return null;
        }
        $count = app(WatchService::class)->watchesForUser($user, $tenantId)->count();
        return $count > 0 ? (string) $count : null;
    }

    /**
     * @return Collection<int,array>
     */
    public function getRows(): Collection
    {
        $user = auth()->user();
        $tenantId = Filament::getTenant()?->id;
        if (! $user || ! $tenantId) {
            return collect();
        }

        return app(WatchService::class)->watchesForUser($user, $tenantId)
            ->map(function ($watch) {
                $inv = $watch->investigation;
                if (! $inv) {
                    return null;
                }
                $lastMeaningful = $watch->notifications()->latest('sent_at')->first();
                return [
                    'watch'          => $watch,
                    'investigation'  => $inv,
                    'status'         => $inv->status,
                    'priority'       => $inv->priority,
                    'revenue_at_risk'=> $inv->revenue_at_risk,
                    'last_change'    => $lastMeaningful?->sent_at ?? $watch->last_evaluated_at,
                    'last_message'   => $lastMeaningful?->message,
                    'next_expected'  => WatchEvaluationService::nextExpectedEvent($inv),
                    'via_team'       => $watch->team_id ? ($watch->team?->name) : null,
                ];
            })
            ->filter()
            ->values();
    }

    public function unwatch(int $investigationId): void
    {
        $tenantId = Filament::getTenant()?->id;
        $user     = auth()->user();
        if (! $tenantId || ! $user) {
            return;
        }
        $investigation = Investigation::where('tenant_id', $tenantId)->find($investigationId);
        if (! $investigation) {
            return;
        }
        app(WatchService::class)->unwatchForUser($investigation, $user->id);
        Notification::make()->title('Stopped watching')->success()->send();
    }
}
