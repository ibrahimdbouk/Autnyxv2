<?php

namespace App\Filament\Resources\WebhookEndpointResource\Pages;

use App\Filament\Resources\WebhookEndpointResource;
use App\Models\WebhookEndpoint;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateWebhookEndpoint extends CreateRecord
{
    protected static string $resource = WebhookEndpointResource::class;

    /** The signing secret, shown once after creation. */
    protected ?string $plainSecret = null;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->plainSecret = WebhookEndpoint::newSecret();

        return $data + ['tenant_id' => Filament::getTenant()?->id, 'secret' => $this->plainSecret, 'created_by' => auth()->id()];
    }

    protected function afterCreate(): void
    {
        Notification::make()->title('Webhook added — signing secret')
            ->body('Shown only now — copy it to the receiving side:  ' . $this->plainSecret)
            ->persistent()->success()->send();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
