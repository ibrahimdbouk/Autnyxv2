<?php

namespace App\Filament\Resources;

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

        return $table
            ->columns([
                TextColumn::make('date')
                    ->date()
                    ->sortable(),

                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable(),

                TextColumn::make('location')
                    ->label('Store')
                    ->placeholder('—')
                    ->searchable()
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
                SelectFilter::make('reason')
                    ->options(fn () => SalesReturn::query()
                        ->whereNotNull('reason')
                        ->distinct()
                        ->orderBy('reason')
                        ->pluck('reason', 'reason')
                        ->toArray()
                    )
                    ->label('Reason'),

                Filter::make('date_range')
                    ->label('Date Range')
                    ->form([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'],  fn (Builder $q, $d) => $q->whereDate('date', '>=', $d))
                            ->when($data['until'], fn (Builder $q, $d) => $q->whereDate('date', '<=', $d));
                    }),
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
