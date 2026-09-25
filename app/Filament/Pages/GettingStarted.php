<?php

namespace App\Filament\Pages;

use App\Services\Onboarding\OnboardingService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * W10 (WP10.5) — guided setup: first file to first investigation. Each step
 * is read from the tenant's data (OnboardingService), with a blank template
 * per file and a button to run the first detection now instead of tonight.
 */
class GettingStarted extends Page
{
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-rocket-launch';

    protected static ?string $navigationLabel = 'Getting started';

    protected static ?int $navigationSort = -10;

    protected static ?string $slug = 'getting-started';

    protected string $view = 'filament.pages.getting-started';

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return (bool) ($u && ($u->is_super_admin || $u->is_tenant_admin));
    }

    public static function getNavigationBadge(): ?string
    {
        $tenantId = Filament::getTenant()?->id;
        if (! $tenantId) {
            return null;
        }
        $left = app(OnboardingService::class)->progress($tenantId)['required_left'];

        return $left > 0 ? (string) $left : null;
    }

    public function getTitle(): string
    {
        return 'Getting started';
    }

    public function steps(): array
    {
        return app(OnboardingService::class)->steps((int) Filament::getTenant()->id);
    }

    public function progress(): array
    {
        return app(OnboardingService::class)->progress((int) Filament::getTenant()->id);
    }

    public function importsUrl(): ?string
    {
        return \App\Filament\Resources\ImportResource::canViewAny() ? \App\Filament\Resources\ImportResource::getUrl('index') : null;
    }

    public function runDetectionAction(): Action
    {
        return Action::make('runDetection')
            ->label('Run detection now')
            ->icon('heroicon-o-play')
            ->requiresConfirmation()
            ->modalDescription('Runs tonight\'s analytics now: profiles, baselines, detection, investigations and narration. It takes a few minutes; this page updates when you reload it.')
            ->action(function () {
                abort_unless(static::canAccess(), 403);
                \Illuminate\Support\Facades\Artisan::queue('nightly:dispatch', ['--tenant' => (int) Filament::getTenant()->id, '--force' => true]);
                Notification::make()->title('Detection queued')->body('Findings appear in a few minutes.')->success()->send();
            });
    }
}
