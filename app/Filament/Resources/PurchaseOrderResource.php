<?php

namespace App\Filament\Resources;
use App\Filament\Concerns\GatesResourceByScreen;

use App\Filament\Resources\PurchaseOrderResource\Pages;
use App\Models\PurchaseOrder;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PurchaseOrderResource extends Resource
{
    use GatesResourceByScreen;

    const SCREEN_KEY = 'purchase_orders';
    protected static ?string $model = PurchaseOrder::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-truck';

    protected static \UnitEnum|string|null $navigationGroup = 'Data';

    protected static ?string $navigationLabel = 'Purchase Orders';

    protected static ?int $navigationSort = 7;

    // Read-only
    public static function canCreate(): bool    { return false; }
    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool   { return false; }
    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool { return false; }

    public static function table(Table $table): Table
    {
        $currency = Filament::getTenant()?->currencyCode() ?? 'USD';

        return $table
            ->columns([
                TextColumn::make('po_number')
                    ->label('PO #')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                TextColumn::make('supplier')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable(),

                TextColumn::make('qty_ordered')
                    ->label('Ordered')
                    ->numeric(decimalPlaces: 0)
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('qty_received')
                    ->label('Received')
                    ->numeric(decimalPlaces: 0)
                    ->alignCenter()
                    ->sortable()
                    ->color(fn (PurchaseOrder $record): string =>
                        $record->qty_received >= $record->qty_ordered ? 'success' : 'warning'
                    ),

                TextColumn::make('unit_cost')
                    ->label('Unit Cost')
                    ->money($currency)
                    ->sortable(),

                TextColumn::make('order_date')
                    ->label('Order Date')
                    ->date()
                    ->sortable(),

                TextColumn::make('expected_date')
                    ->label('Expected')
                    ->date()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('received_date')
                    ->label('Received')
                    ->date()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('supplier')
                    ->options(fn () => PurchaseOrder::query()
                        ->whereNotNull('supplier')
                        ->distinct()
                        ->orderBy('supplier')
                        ->pluck('supplier', 'supplier')
                        ->toArray()
                    )
                    ->label('Supplier'),
            ])
            ->emptyStateIcon('heroicon-o-truck')
            ->emptyStateHeading('No purchase orders yet')
            ->emptyStateDescription('Purchase orders appear here after a PO import.')
            ->defaultSort('order_date', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPurchaseOrders::route('/'),
        ];
    }
}
