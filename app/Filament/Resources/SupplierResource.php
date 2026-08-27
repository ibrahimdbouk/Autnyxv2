<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupplierResource\Pages;
use App\Models\Supplier;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-truck';

    protected static \UnitEnum|string|null $navigationGroup = 'Data';

    protected static ?string $navigationLabel = 'Suppliers';

    protected static ?int $navigationSort = 7;

    // Read-only: suppliers are created/enriched during import
    public static function canCreate(): bool { return false; }
    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool { return false; }
    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool { return false; }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Supplier')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),

                TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('specialization')
                    ->label('Specialization')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('lead_time_days')
                    ->label('Lead Time (d)')
                    ->numeric()
                    ->alignCenter()
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('contact_email')
                    ->label('Email')
                    ->placeholder('—')
                    ->copyable()
                    ->toggleable(),

                TextColumn::make('purchase_orders_count')
                    ->counts('purchaseOrders')
                    ->label('POs')
                    ->alignCenter()
                    ->badge()
                    ->color('primary')
                    ->sortable(),

                // Products aren't linked to suppliers by a direct FK — a supplier
                // supplies products *through* its purchase orders. Count the distinct
                // products across this supplier's POs.
                TextColumn::make('products_count')
                    ->label('Products')
                    ->alignCenter()
                    ->getStateUsing(fn (Supplier $record): int => $record->purchaseOrders()->distinct()->count('product_id'))
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(fn () => Supplier::query()
                        ->whereNotNull('type')
                        ->distinct()
                        ->orderBy('type')
                        ->pluck('type', 'type')
                        ->toArray()
                    )
                    ->label('Type'),

                SelectFilter::make('specialization')
                    ->options(fn () => Supplier::query()
                        ->whereNotNull('specialization')
                        ->distinct()
                        ->orderBy('specialization')
                        ->pluck('specialization', 'specialization')
                        ->toArray()
                    )
                    ->label('Specialization'),
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
            'index' => Pages\ListSuppliers::route('/'),
        ];
    }
}
