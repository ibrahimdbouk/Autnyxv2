<?php

namespace App\Filament\Resources\ImportResource\Pages;

use App\Filament\Resources\ImportResource;
use App\Models\Import;
use App\Models\ImportRow;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;

class ViewImport extends Page
{
    use WithPagination;

    protected static string $resource = ImportResource::class;

    protected static string $view = 'filament.resources.import-resource.pages.view-import';

    protected static ?string $title = 'Import Details';

    public Import $record;

    public string $statusFilter = 'pending_review';

    public function mount(Import $record): void
    {
        $this->record = $record;
    }

    #[Computed]
    public function failedRows()
    {
        return ImportRow::where('import_id', $this->record->id)
            ->where('status', $this->statusFilter)
            ->orderBy('row_number')
            ->paginate(25);
    }

    public function approveRow(int $rowId): void
    {
        ImportRow::where('id', $rowId)->update(['status' => ImportRow::STATUS_APPROVED]);
        $this->resetPage();
    }

    public function rejectRow(int $rowId): void
    {
        ImportRow::where('id', $rowId)->update(['status' => ImportRow::STATUS_REJECTED]);
        $this->resetPage();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('All Imports')
                ->color('gray')
                ->url(ListImports::getUrl()),
        ];
    }
}
