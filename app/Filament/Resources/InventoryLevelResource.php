<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InventoryLevelResource\Pages;
use App\Models\InventoryLevel;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InventoryLevelResource extends Resource
{
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
        return $table
            ->columns([
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                TextColumn::make('location')
                    ->searchable()
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
                SelectFilter::make('location')
                    ->options(fn () => InventoryLevel::query()
                        ->whereNotNull('location')
                        ->distinct()
                        ->orderBy('location')
                        ->pluck('location', 'location')
                        ->toArray()
                    )
                    ->label('Location'),
            ])
            ->emptyStateIcon('heroicon-o-archive-box')
            ->emptyStateHeading('No inventory records yet')
            ->emptyStateDescription('Inventory levels appear here after an inventory import.')
            ->defaultSort('sku');
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
