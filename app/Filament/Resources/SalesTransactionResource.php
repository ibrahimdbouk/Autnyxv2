<?php

namespace App\Filament\Resources;
use App\Filament\Concerns\GatesResourceByScreen;

use App\Filament\Resources\SalesTransactionResource\Pages;
use App\Models\SalesTransaction;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Forms\Components\DatePicker;
use Illuminate\Database\Eloquent\Builder;

class SalesTransactionResource extends Resource
{
    use GatesResourceByScreen;

    const SCREEN_KEY = 'sales';
    protected static ?string $model = SalesTransaction::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-shopping-cart';

    protected static \UnitEnum|string|null $navigationGroup = 'Data';

    protected static ?string $navigationLabel = 'Sales';

    protected static ?int $navigationSort = 4;

    // Read-only
    public static function canCreate(): bool    { return false; }
    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool   { return false; }
    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool { return false; }

    public static function table(Table $table): Table
    {
        $currency = Filament::getTenant()?->currencyCode() ?? 'USD';

        return \App\Filament\Support\FactTable::configure($table)   // WP6.4
            ->columns([
                TextColumn::make('transaction_id')
                    ->label('Transaction ID')
                    ->searchable(query: \App\Filament\Support\FactTable::searchExact('transaction_id'))
                    ->copyable(),

                TextColumn::make('date')
                    ->date()
                    ->sortable(),

                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable(query: \App\Filament\Support\FactTable::searchExact('sku')),

                TextColumn::make('location')
                    ->searchable(query: fn (Builder $query, string $search) => \App\Filament\Support\FactTable::searchStore($query, $search))
                    ->badge()
                    ->color('gray'),

                TextColumn::make('quantity')
                    ->label('Qty')
                    ->numeric(decimalPlaces: 0)
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('unit_price')
                    ->label('Unit Price')
                    ->money($currency)
                    ->sortable(),

                TextColumn::make('total_amount')
                    ->label('Total')
                    ->money($currency)
                    ->sortable()
                    ->weight('bold'),
            ])
            ->filters([
                \App\Filament\Support\FactTable::storeFilter(),
                \App\Filament\Support\FactTable::dateRange('date_range', 'date', 'Date', 30),
            ])
            ->emptyStateIcon('heroicon-o-shopping-cart')
            ->emptyStateHeading('No sales yet')
            ->emptyStateDescription('Sales transactions appear here after a sales import.')
            ->defaultSort('date', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSalesTransactions::route('/'),
        ];
    }
}
