<?php

namespace App\Filament\Ops\Pages;

use App\Services\Ops\PlatformHealthService;
use Filament\Actions\Action;
use Filament\Pages\Page;

/**
 * Ops — is the platform healthy right now? Nightly pipeline status, import
 * health, DB size, queue failures.
 */
class PlatformHealth extends Page
{
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-heart';

    protected static ?string $navigationLabel = 'Platform Health';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.ops.pages.platform-health';

    public function getTitle(): string
    {
        return 'Platform Health';
    }

    /** @return array<string,mixed> */
    public function getData(): array
    {
        $svc = app(PlatformHealthService::class);

        return [
            'summary'   => $svc->summary(),
            'pipeline'  => $svc->pipeline(),
            'failures'  => $svc->recentFailures(),
            'imports'   => $svc->imports(),
            'database'  => $svc->database(),
            'lastPurge' => $svc->lastPurge(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')->label('Refresh')->icon('heroicon-o-arrow-path')
                ->action(fn () => null),
        ];
    }
}
