<?php

namespace App\Filament\Resources\ApiConnectionResource\Pages;

use App\Filament\Resources\ApiConnectionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditApiConnection extends EditRecord
{
    protected static string $resource = ApiConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->requiresConfirmation(),
        ];
    }

    /**
     * WP2.2 (audit M5): pre-fill only the NON-secret auth settings. Tokens,
     * passwords and client secrets never enter the Livewire payload.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $stored = (array) ($this->record->auth_config ?? []);
        $data['auth_config'] = array_diff_key($stored, array_flip(ApiConnectionResource::SECRET_KEYS));

        return $data;
    }

    /** A blank secret field means "keep the stored value". */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $stored   = (array) ($this->record->auth_config ?? []);
        $incoming = (array) ($data['auth_config'] ?? []);

        foreach (ApiConnectionResource::SECRET_KEYS as $key) {
            if (blank($incoming[$key] ?? null) && array_key_exists($key, $stored)) {
                $incoming[$key] = $stored[$key];
            }
        }
        $data['auth_config'] = array_merge(array_diff_key($stored, $incoming), $incoming);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
