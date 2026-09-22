<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StoreResource\Pages;
use App\Models\Store;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StoreResource extends Resource
{
    protected static ?string $model = Store::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static \UnitEnum|string|null $navigationGroup = 'Data';

    protected static ?int $navigationSort = 9;

    // Read-only: stores are created automatically during import
    public static function canCreate(): bool    { return false; }
    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool   { return false; }
    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool { return false; }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('feature');
    }

    /** Format an amount in the current tenant's currency, tolerating a null state. */
    private static function money($state): string
    {
        if ($state === null) {
            return '—';
        }

        return Filament::getTenant()?->money((float) $state) ?? number_format((float) $state, 2);
    }

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
                    ->toggleable(isToggledHiddenByDefault: true),

                // Behavioural profile (store feature layer).
                TextColumn::make('feature.descriptor')
                    ->label('Profile')
                    ->badge()->color('primary')
                    ->placeholder('Not yet profiled')
                    ->wrap(),

                TextColumn::make('feature.price_tier')
                    ->label('Price')->badge()->placeholder('—')
                    ->color(fn (?string $state) => match ($state) {
                        'premium' => 'warning', 'value' => 'info', default => 'gray',
                    })
                    ->toggleable(),

                TextColumn::make('feature.size_tier')
                    ->label('Size')->badge()->color('gray')->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('feature.dominant_segment')
                    ->label('Demand')->badge()->color('gray')->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('feature.revenue')
                    ->label('90-day revenue')->alignEnd()->placeholder('—')
                    ->formatStateUsing(fn ($state) => static::money($state))
                    ->toggleable(),

                TextColumn::make('feature.avg_basket_value')
                    ->label('Avg basket')->alignEnd()->placeholder('—')
                    ->formatStateUsing(fn ($state) => static::money($state))
                    ->toggleable(isToggledHiddenByDefault: true),

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
            ->filters([
                SelectFilter::make('city')
                    ->options(fn () => Store::query()
                        ->whereNotNull('city')
                        ->distinct()
                        ->orderBy('city')
                        ->pluck('city', 'city')
                        ->toArray()
                    )
                    ->label('City')
                    ->multiple(),

                SelectFilter::make('country')
                    ->options(fn () => Store::query()
                        ->whereNotNull('country')
                        ->distinct()
                        ->orderBy('country')
                        ->pluck('country', 'country')
                        ->toArray()
                    )
                    ->label('Country')
                    ->multiple(),

                SelectFilter::make('price_tier')
                    ->label('Price tier')
                    ->options(fn () => Store::query()
                        ->with('feature')
                        ->get()
                        ->pluck('feature.price_tier')
                        ->filter()
                        ->unique()
                        ->sort()
                        ->mapWithKeys(fn ($v) => [$v => ucwords(str_replace('_', ' ', (string) $v))])
                        ->toArray()
                    )
                    ->multiple()
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['values'] ?? null, fn (Builder $q, $values) => $q
                            ->whereHas('feature', fn (Builder $fq) => $fq->whereIn('price_tier', $values)))),

                SelectFilter::make('size_tier')
                    ->label('Size tier')
                    ->options(fn () => Store::query()
                        ->with('feature')
                        ->get()
                        ->pluck('feature.size_tier')
                        ->filter()
                        ->unique()
                        ->sort()
                        ->mapWithKeys(fn ($v) => [$v => ucwords(str_replace('_', ' ', (string) $v))])
                        ->toArray()
                    )
                    ->multiple()
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['values'] ?? null, fn (Builder $q, $values) => $q
                            ->whereHas('feature', fn (Builder $fq) => $fq->whereIn('size_tier', $values)))),

                SelectFilter::make('dominant_segment')
                    ->label('Dominant demand segment')
                    ->options(fn () => Store::query()
                        ->with('feature')
                        ->get()
                        ->pluck('feature.dominant_segment')
                        ->filter()
                        ->unique()
                        ->sort()
                        ->mapWithKeys(fn ($v) => [$v => ucwords(str_replace('_', ' ', (string) $v))])
                        ->toArray()
                    )
                    ->multiple()
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['values'] ?? null, fn (Builder $q, $values) => $q
                            ->whereHas('feature', fn (Builder $fq) => $fq->whereIn('dominant_segment', $values)))),
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
