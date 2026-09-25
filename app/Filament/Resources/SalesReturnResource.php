<?php

namespace App\Filament\Resources;
use App\Filament\Concerns\GatesResourceByScreen;

use App\Filament\Resources\SalesReturnResource\Pages;
use App\Models\SalesReturn;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SalesReturnResource extends Resource
{
    use GatesResourceByScreen;

    const SCREEN_KEY = 'returns';
    protected static ?string $model = SalesReturn::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static \UnitEnum|string|null $navigationGroup = 'Data';

    protected static ?string $navigationLabel = 'Returns';

    protected static ?int $navigationSort = 5;

    // Read-only
    public static function canCreate(): bool { return false; }
    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool { return false; }
    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool { return false; }

    public static function table(Table $table): Table
    {
        $currency = Filament::getTenant()?->currencyCode() ?? 'USD';

        return \App\Filament\Support\FactTable::configure($table)   // WP6.4
            ->columns([
                TextColumn::make('date')
                    ->date()
                    ->sortable(),

                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable(query: \App\Filament\Support\FactTable::searchExact('sku')),

                TextColumn::make('location')
                    ->label('Store')
                    ->placeholder('—')
                    ->searchable(query: fn (Builder $query, string $search) => \App\Filament\Support\FactTable::searchStore($query, $search))
                    ->badge()
                    ->color('gray'),

                TextColumn::make('quantity')
                    ->label('Qty')
                    ->numeric(decimalPlaces: 0)
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('value')
                    ->label('Value')
                    ->money($currency)
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('reason')
                    ->label('Reason')
                    ->placeholder('—')
                    ->searchable()
                    ->wrap(),
            ])
            ->filters([
                \App\Filament\Support\FactTable::storeFilter(),

                SelectFilter::make('reason')
                    // WP6.4: the reasons of the last year (bounded), not a DISTINCT over all returns.
                    ->options(fn () => SalesReturn::query()
                        ->where('tenant_id', Filament::getTenant()?->id)
                        ->where('date', '>=', now()->subYear()->toDateString())
                        ->whereNotNull('reason')
                        ->distinct()
                        ->orderBy('reason')
                        ->limit(200)
                        ->pluck('reason', 'reason')
                        ->toArray()
                    )
                    ->label('Reason'),

                \App\Filament\Support\FactTable::dateRange('date_range', 'date', 'Date', 90),
            ])
            ->defaultSort('date', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSalesReturns::route('/'),
        ];
    }
}
