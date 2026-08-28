<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReplenishmentResource\Pages;
use App\Models\SkuReplenishment;
use App\Support\Money;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * B4 — the "what to buy" list. Read-only view of derived replenishment targets,
 * ranked by suggested order quantity so the most urgent buys are on top.
 */
class ReplenishmentResource extends Resource
{
    protected static ?string $model = SkuReplenishment::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-shopping-cart';

    protected static \UnitEnum|string|null $navigationGroup = 'Intelligence';

    protected static ?string $navigationLabel = 'Replenishment';

    protected static ?int $navigationSort = 4;

    // Read-only: these are computed nightly, not hand-edited.
    public static function canCreate(): bool { return false; }
    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool { return false; }
    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool { return false; }

    public static function table(Table $table): Table
    {
        $currency = Filament::getTenant()?->currencyCode();

        return $table
            ->columns([
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                TextColumn::make('store.name')
                    ->label('Store')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('segment')
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('daily_rate')
                    ->label('Rate /day')
                    ->numeric(decimalPlaces: 2)
                    ->alignCenter()
                    ->sortable()
                    ->tooltip('Expected units sold per day (best-fit demand rate).'),

                TextColumn::make('lead_time_days')
                    ->label('Lead')
                    ->suffix(' d')
                    ->numeric(decimalPlaces: 0)
                    ->alignCenter()
                    ->toggleable(),

                TextColumn::make('reorder_point')
                    ->label('Reorder pt')
                    ->numeric(decimalPlaces: 0)
                    ->alignCenter()
                    ->color('warning')
                    ->sortable()
                    ->tooltip('Order when on-hand falls to here: demand over lead time + safety stock.'),

                TextColumn::make('on_hand')
                    ->label('On hand')
                    ->numeric(decimalPlaces: 0)
                    ->alignCenter()
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('suggested_order_qty')
                    ->label('Order qty')
                    ->numeric(decimalPlaces: 0)
                    ->alignCenter()
                    ->weight('bold')
                    ->color(fn (SkuReplenishment $r): string => $r->suggested_order_qty > 0 ? 'danger' : 'gray')
                    ->sortable(),

                TextColumn::make('order_value')
                    ->label('Order value')
                    ->formatStateUsing(fn ($state) => Money::format((float) $state, $currency, 0))
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('supplier')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('computed_at')
                    ->label('Computed')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('needs_order')
                    ->label('Needs ordering')
                    ->query(fn (Builder $q) => $q->where('suggested_order_qty', '>', 0))
                    ->default(),

                SelectFilter::make('segment')
                    ->options(fn () => SkuReplenishment::query()
                        ->whereNotNull('segment')->distinct()
                        ->orderBy('segment')->pluck('segment', 'segment')->toArray()),
            ])
            ->defaultSort('suggested_order_qty', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReplenishment::route('/'),
        ];
    }
}
