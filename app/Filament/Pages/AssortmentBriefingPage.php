<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GatesAssortmentScreen;
use App\Filament\Widgets\Assortment\BriefingDecisions;
use App\Filament\Widgets\Assortment\BriefingPlans;
use App\Filament\Widgets\Assortment\BriefingStats;
use App\Filament\Widgets\Assortment\BriefingSummary;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Assortment\BriefingService;
use App\Services\Assortment\TenantAssortment;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * v2.1 — the weekly assortment briefing, as a page of widgets: the headline
 * and what to do (summary), the week in figures (stats), and the decisions and
 * plans the rules put first (tables). Head office only: it covers every store.
 */
class AssortmentBriefingPage extends Page
{
    use GatesAssortmentScreen;

    const APP_KEY = Tenant::APP_ASSORTMENT;

    const SCREEN_KEY = 'assortment_briefing';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-newspaper';

    protected static \UnitEnum|string|null $navigationGroup = 'Assortment';

    protected static ?string $navigationLabel = 'Briefing';

    protected static ?int $navigationSort = 0;

    protected static ?string $slug = 'assortment-briefing';

    protected string $view = 'filament.assortment.briefing';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return static::assortmentAllowed() && $user instanceof User
            && ($user->canManageUsers() || in_array($user->org_role, [null, User::ORG_HQ], true));
    }

    public function getTitle(): string
    {
        return 'Assortment briefing';
    }

    public function briefingId(): ?int
    {
        $tenant = Filament::getTenant();

        return $tenant ? app(BriefingService::class)->latest($tenant)?->id : null;
    }

    protected function getHeaderWidgets(): array
    {
        return $this->briefingId() ? [BriefingSummary::class, BriefingStats::class, BriefingDecisions::class, BriefingPlans::class] : [];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function getWidgetData(): array
    {
        return ['briefing' => $this->briefingId()];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('rebuild')->label('Build this week\'s again')->icon('heroicon-o-arrow-path')->color('gray')
                ->visible(fn () => ($t = Filament::getTenant()) instanceof Tenant && TenantAssortment::isLive($t))
                ->action(function () {
                    app(BriefingService::class)->generate(Filament::getTenant());
                    Notification::make()->title('Briefing rebuilt from the latest records')->success()->send();
                    $this->redirect(static::getUrl(), navigate: true);
                }),
        ];
    }

    protected function getViewData(): array
    {
        $tenant = Filament::getTenant();

        return ['live' => $tenant instanceof Tenant && TenantAssortment::isLive($tenant), 'has' => $this->briefingId() !== null];
    }
}
