<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ImportResource\Pages;
use App\Models\Import;
use App\Services\Import\ImportProcessorService;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Actions\Action;

class ImportResource extends Resource
{
    protected static ?string $model = Import::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static \UnitEnum|string|null $navigationGroup = 'Data';

    protected static ?string $navigationLabel = 'Data Imports';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        $user = auth()->user();
        return $user && ($user->is_super_admin || $user->is_tenant_admin);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('original_filename')
                    ->label('File')
                    ->searchable()
                    ->limit(40),

                TextColumn::make('data_type')
                    ->label('Type')
                    ->formatStateUsing(fn ($state) => Import::dataTypeLabels()[$state] ?? $state)
                    ->badge()
                    ->color('primary'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'completed'              => 'success',
                        'completed_with_errors'  => 'warning',
                        'failed'                 => 'danger',
                        'importing'              => 'info',
                        'mapping_review'         => 'warning',
                        default                  => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'uploaded'               => 'Uploaded',
                        'mapping_review'         => 'Awaiting Review',
                        'importing'              => 'Importing…',
                        'completed'              => 'Completed',
                        'completed_with_errors'  => 'Completed (errors)',
                        'failed'                 => 'Failed',
                        'rolled_back'            => 'Rolled back',
                        default                  => ucfirst($state),
                    }),

                TextColumn::make('total_rows')
                    ->label('Rows')
                    ->numeric()
                    ->alignCenter(),

                TextColumn::make('imported_rows')
                    ->label('Imported')
                    ->numeric()
                    ->alignCenter()
                    ->color('success'),

                TextColumn::make('failed_rows')
                    ->label('Failed')
                    ->numeric()
                    ->alignCenter()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'gray'),

                TextColumn::make('user.name')
                    ->label('Uploaded by')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Uploaded')
                    ->dateTime()
                    ->sortable()
                    ->since(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Action::make('review')
                    ->label('Review Mapping')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Import $record) => Pages\ReviewMapping::getUrl(['record' => $record]))
                    ->visible(fn (Import $record) => $record->status === Import::STATUS_MAPPING_REVIEW),

                Action::make('view_errors')
                    ->label('View Errors')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->url(fn (Import $record) => Pages\ViewImport::getUrl(['record' => $record]))
                    ->visible(fn (Import $record) => in_array($record->status, [
                        Import::STATUS_COMPLETED_WITH_ERRORS,
                        Import::STATUS_FAILED,
                    ]))
                    ->color('danger'),

                Action::make('rollback')
                    ->label('Undo Import')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Undo this import?')
                    ->modalDescription('This permanently deletes every row this import added. Other imports and your master data are unaffected. This cannot be undone.')
                    ->modalSubmitActionLabel('Yes, delete these rows')
                    ->visible(fn (Import $record) => $record->canRollback())
                    ->action(function (Import $record) {
                        $deleted = app(ImportProcessorService::class)->rollback($record);
                        Notification::make()
                            ->title('Import undone')
                            ->body("{$deleted} row(s) removed.")
                            ->success()
                            ->send();
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            // NB: no 'create' page — uploads go through the "Upload File" modal
            // action on the index (ListImports). The old CreateImport page was a
            // hand-rolled Livewire form that never bound its fields correctly.
            'index'          => Pages\ListImports::route('/'),
            'review-mapping' => Pages\ReviewMapping::route('/{record}/review'),
            'process'        => Pages\ProcessImport::route('/{record}/process'),
            'view'           => Pages\ViewImport::route('/{record}'),
        ];
    }
}
