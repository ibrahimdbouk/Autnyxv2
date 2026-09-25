<?php

namespace App\Filament\Ops\Resources\TenantResource\Pages;

use App\Filament\Ops\Resources\TenantResource;
use Filament\Resources\Pages\EditRecord;

class EditTenant extends EditRecord
{
    protected static string $resource = TenantResource::class;

    /** WP7.4: the opt-in lives in settings.autonomy; other settings are kept. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['execution_opt_in'] = $this->record->executionOptedIn();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $settings = is_array($this->record->settings) ? $this->record->settings : [];
        $optIn    = (bool) ($data['execution_opt_in'] ?? false);
        if ($optIn !== $this->record->executionOptedIn()) {
            $settings['autonomy']['execution_opt_in']   = $optIn;
            $settings['autonomy']['execution_set_by']   = auth()->user()?->email;
            $settings['autonomy']['execution_set_at']   = now()->toIso8601String();
            $data['settings'] = $settings;
        }
        unset($data['execution_opt_in']);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
