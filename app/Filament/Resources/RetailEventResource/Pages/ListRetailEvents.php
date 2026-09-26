<?php

namespace App\Filament\Resources\RetailEventResource\Pages;

use App\Filament\Resources\RetailEventResource;
use App\Services\Calendar\RetailCalendarDefaults;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;

class ListRetailEvents extends ListRecords
{
    protected static string $resource = RetailEventResource::class;

    public function getTitle(): string
    {
        return 'Retail Calendar';
    }

    public function mount(): void
    {
        // The defaults exist before anyone looks for them.
        app(RetailCalendarDefaults::class)->ensure((int) Filament::getTenant()?->id);
        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add your own event')];
    }
}
