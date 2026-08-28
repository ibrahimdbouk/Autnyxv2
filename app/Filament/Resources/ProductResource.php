<?php

namespace App\Filament\Resources;
use App\Filament\Concerns\GatesResourceByScreen;

use App\Filament\Resources\ProductResource\Pages;
use App\Models\Product;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProductResource extends Resource
{
    use GatesResourceByScreen;

    const SCREEN_KEY = 'products';
    protected static ?string $model = Product::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-cube';

    protected static \UnitEnum|string|null $navigationGroup = 'Data';

    protected static ?int $navigationSort = 3;

    // Read-only: no create / edit / delete
    public static function canCreate(): bool    { return false; }
    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool   { return false; }
    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool { return false; }

    public static function table(Table $table): Table
    {
        // Money columns follow the tenant's display currency (B1 — relabel, no conversion).
        $currency = Filament::getTenant()?->currencyCode() ?? 'USD';

        return $table
            ->columns([
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('category')
                    ->badge()
                    ->color('primary')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('subcategory')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('unit_cost')
                    ->label('Unit Cost')
                    ->money($currency)
                    ->sortable(),

                TextColumn::make('selling_price')
                    ->label('Selling Price')
                    ->money($currency)
                    ->sortable(),

                TextColumn::make('supplier')
                    ->searchable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->options(fn () => Product::query()
                        ->whereNotNull('category')
                        ->distinct()
                        ->orderBy('category')
                        ->pluck('category', 'category')
                        ->toArray()
                    )
                    ->label('Category'),
            ])
            ->emptyStateIcon('heroicon-o-cube')
            ->emptyStateHeading('No products yet')
            ->emptyStateDescription('Products appear here after a master-data import.')
            ->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
        ];
    }
}
