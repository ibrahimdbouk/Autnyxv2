<?php

namespace App\Filament\Resources\TeamsConnectionResource\Pages;

use App\Filament\Resources\TeamsConnectionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTeamsConnection extends EditRecord
{
    protected static string $resource = TeamsConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // WP2.2: link the Microsoft 365 tenant by signed admin sign-in + consent.
            \Filament\Actions\Action::make('connect_microsoft')
                ->label(fn () => $this->record->isMicrosoftTenantVerified() ? 'Re-connect Microsoft 365' : 'Connect Microsoft 365')
                ->icon('heroicon-o-link')
                ->url(fn () => route('teams.consent.connect', ['tenant' => \Filament\Facades\Filament::getTenant()?->slug])),
            DeleteAction::make()->requiresConfirmation(),
        ];
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if ($msg = session('teams_consent_error')) {
            \Filament\Notifications\Notification::make()->title('Microsoft 365 not linked')->body($msg)->danger()->send();
        } elseif ($msg = session('teams_consent_ok')) {
            \Filament\Notifications\Notification::make()->title($msg)->success()->send();
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
