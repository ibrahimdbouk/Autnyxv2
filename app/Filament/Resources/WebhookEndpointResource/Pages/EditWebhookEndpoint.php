<?php

namespace App\Filament\Resources\WebhookEndpointResource\Pages;

use App\Filament\Resources\WebhookEndpointResource;
use Filament\Resources\Pages\EditRecord;

class EditWebhookEndpoint extends EditRecord
{
    protected static string $resource = WebhookEndpointResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Switching an endpoint back on clears the switch-off.
        if (! empty($data['active']) && ! $this->record->active) {
            $data['disabled_reason'] = null;
            $data['failure_streak'] = 0;
        }

        return $data;
    }
}
