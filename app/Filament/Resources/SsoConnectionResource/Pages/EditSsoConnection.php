<?php

namespace App\Filament\Resources\SsoConnectionResource\Pages;

use App\Filament\Resources\SsoConnectionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSsoConnection extends EditRecord
{
    protected static string $resource = SsoConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // WP2.1: check the DNS TXT records and mark the proven domains verified.
            \Filament\Actions\Action::make('verify_domains')
                ->label('Verify domains')
                ->icon('heroicon-o-shield-check')
                ->action(function () {
                    $result = app(\App\Services\Sso\SsoDomainVerifier::class)->verify($this->record);
                    $this->refreshFormData(['allowed_domains']);

                    $body = collect($result['failed'])->map(fn ($why, $d) => "{$d}: {$why}")->implode("\n");
                    \Filament\Notifications\Notification::make()
                        ->title(count($result['verified']) . ' domain(s) verified')
                        ->body($body ?: null)
                        ->color($result['failed'] === [] ? 'success' : 'warning')
                        ->send();

                    \App\Models\AuditLog::create([
                        'tenant_id'   => $this->record->tenant_id,
                        'user_id'     => auth()->id(),
                        'event_type'  => 'sso_domains_verified',
                        'description' => 'SSO domain verification: ' . (implode(', ', $result['verified']) ?: 'none verified'),
                    ]);
                }),
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
