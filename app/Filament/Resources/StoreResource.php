<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StoreResource\Pages;
use App\Models\Store;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class StoreResource extends Resource
{
    protected static ?string $model = Store::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static \UnitEnum|string|null $navigationGroup = 'Data';

    protected static ?int $navigationSort = 6;

    // Read-only: stores are created automatically during import
    public static function canCreate(): bool    { return false; }
    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool   { return false; }
    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool { return false; }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('city')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('country')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('sales_transactions_count')
                    ->counts('salesTransactions')
                    ->label('Sales Rows')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('inventory_levels_count')
                    ->counts('inventoryLevels')
                    ->label('Inventory Rows')
                    ->alignCenter()
                    ->sortable(),
            ])
            ->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStores::route('/'),
        ];
    }
}
