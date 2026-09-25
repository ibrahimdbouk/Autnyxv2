<?php

namespace App\Filament\Resources;
use App\Filament\Concerns\GatesResourceByScreen;

use App\Filament\Resources\InventoryLevelResource\Pages;
use App\Models\InventoryLevel;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InventoryLevelResource extends Resource
{
    use GatesResourceByScreen;

    const SCREEN_KEY = 'inventory';
    protected static ?string $model = InventoryLevel::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-archive-box';

    protected static \UnitEnum|string|null $navigationGroup = 'Data';

    protected static ?string $navigationLabel = 'Inventory';

    protected static ?int $navigationSort = 6;

    // Read-only
    public static function canCreate(): bool    { return false; }
    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool   { return false; }
    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool { return false; }

    public static function table(Table $table): Table
    {
        return \App\Filament\Support\FactTable::configure($table)   // WP6.4
            ->columns([
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable(query: \App\Filament\Support\FactTable::searchExact('sku'))
                    ->sortable()
                    ->copyable(),

                TextColumn::make('location')
                    ->searchable(query: fn (Builder $query, string $search) => \App\Filament\Support\FactTable::searchStore($query, $search))
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('on_hand_qty')
                    ->label('On Hand')
                    ->numeric(decimalPlaces: 0)
                    ->alignCenter()
                    ->sortable()
                    ->color(fn (InventoryLevel $record): string =>
                        $record->reorder_point !== null && $record->on_hand_qty <= $record->reorder_point
                            ? 'danger'
                            : 'success'
                    )
                    ->weight('bold'),

                TextColumn::make('reorder_point')
                    ->label('Reorder Point')
                    ->numeric(decimalPlaces: 0)
                    ->alignCenter()
                    ->sortable()
                    ->color('warning'),

                TextColumn::make('as_of_date')
                    ->label('As Of')
                    ->date()
                    ->sortable(),
            ])
            ->filters([
                \App\Filament\Support\FactTable::storeFilter(),

                Filter::make('below_reorder')
                    ->label('At or below reorder point')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereNotNull('reorder_point')
                        ->whereColumn('on_hand_qty', '<=', 'reorder_point'))
                    ->toggle(),

                Filter::make('out_of_stock')
                    ->label('Out of stock')
                    ->query(fn (Builder $query): Builder => $query->where('on_hand_qty', '<=', 0))
                    ->toggle(),

                // WP6.4: the recent snapshots by default — this page is the history.
                \App\Filament\Support\FactTable::dateRange('as_of_range', 'as_of_date', 'As-of date', 14),
            ])
            ->emptyStateIcon('heroicon-o-archive-box')
            ->emptyStateHeading('No inventory records yet')
            ->emptyStateDescription('Inventory levels appear here after an inventory import.')
            ->defaultSort('as_of_date', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInventoryLevels::route('/'),
        ];
    }
}
