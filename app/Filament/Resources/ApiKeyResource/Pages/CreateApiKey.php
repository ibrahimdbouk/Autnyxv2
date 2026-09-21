<?php

namespace App\Filament\Resources\ApiKeyResource\Pages;

use App\Filament\Resources\ApiKeyResource;
use App\Models\ApiKey;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateApiKey extends CreateRecord
{
    protected static string $resource = ApiKeyResource::class;

    /** The one-time plaintext token, surfaced to the user after creation. */
    protected ?string $plainToken = null;

    protected function handleRecordCreation(array $data): Model
    {
        [$key, $token] = ApiKey::generate(
            (int) Filament::getTenant()?->id,
            $data['name'],
            $data['scopes'] ?? [],
            $data['expires_at'] ?? null,
            auth()->id(),
        );

        $this->plainToken = $token;

        return $key;
    }

    protected function afterCreate(): void
    {
        Notification::make()
            ->title('API key created — copy it now')
            ->body('This token is shown only once and cannot be retrieved later:  ' . $this->plainToken)
            ->success()
            ->persistent()
            ->send();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
