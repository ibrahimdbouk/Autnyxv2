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

    /** WP2.4 (audit M9): honour screen permissions (was reachable by URL). */
    public static function canViewAny(): bool
    {
        return auth()->user()?->canSeeScreen('stores') ?? false;
    }

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static \UnitEnum|string|null $navigationGroup = 'Data';

    protected static ?int $navigationSort = 9;

    // Platform core: admins can add and edit stores here (a Task-Execution-only
    // tenant has no sales file to create them from). Never deleted from the UI —
    // sales, stock and work history point at them.
    public static function canCreate(): bool
    {
        return (bool) auth()->user()?->canManageImports();
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return (bool) auth()->user()?->canManageImports() && (int) $record->tenant_id === (int) Filament::getTenant()?->id;
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool { return false; }

    /** Name and code are unique per tenant, ignoring case and spacing (as imports match them). */
    private static function uniqueInTenant(string $column): \Closure
    {
        return fn (?Store $record) => function (string $attribute, $value, \Closure $fail) use ($column, $record) {
            if ($value === null || trim((string) $value) === '') {
                return;
            }
            $taken = Store::where('tenant_id', Filament::getTenant()?->id)
                ->whereRaw("lower(regexp_replace(trim({$column}), '\\s+', ' ', 'g')) = ?", [mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $value)))])
                ->when($record?->exists, fn ($q) => $q->whereKeyNot($record->getKey()))
                ->exists();
            if ($taken) {
                $fail("Another store already uses this {$column}.");
            }
        };
    }

    public static function form(\Filament\Schemas\Schema $form): \Filament\Schemas\Schema
    {
        $tenantId = fn () => Filament::getTenant()?->id;
        $suggest = fn (string $col) => fn () => Store::where('tenant_id', $tenantId())->whereNotNull($col)->where($col, '<>', '')
            ->distinct()->orderBy($col)->limit(200)->pluck($col)->all();

        return $form->columns(1)->schema([
            \Filament\Schemas\Components\Section::make('Store')->columns(3)->schema([
                \Filament\Forms\Components\TextInput::make('name')->required()->maxLength(255)
                    ->rules([static::uniqueInTenant('name')]),
                \Filament\Forms\Components\TextInput::make('code')->label('Store code')->maxLength(60)
                    ->rules([static::uniqueInTenant('code')])
                    ->helperText('As it appears in your sales and stock files.'),
                \Filament\Forms\Components\TextInput::make('format')->maxLength(60)->datalist($suggest('format')),
                \Filament\Forms\Components\TextInput::make('banner')->maxLength(60)->datalist($suggest('banner')),
                \Filament\Forms\Components\TextInput::make('status')->maxLength(30)->placeholder('active'),
                \Filament\Forms\Components\DatePicker::make('opened_on')->label('Opened'),
            ]),
            \Filament\Schemas\Components\Section::make('Place in the organisation')
                ->description('Region and area build the store structure (Region → Area → Store) that managers, reporting and escalation follow. Assign managers under Administration → Regions & Areas.')
                ->columns(2)->schema([
                    \Filament\Forms\Components\TextInput::make('region')->maxLength(120)->datalist($suggest('region')),
                    \Filament\Forms\Components\TextInput::make('area')->maxLength(120)->datalist($suggest('area'))
                        ->helperText('Optional: the level an area manager looks after, inside the region.'),
                ]),
            \Filament\Schemas\Components\Section::make('Location')->columns(3)->schema([
                \Filament\Forms\Components\TextInput::make('address')->maxLength(255)->columnSpan(2),
                \Filament\Forms\Components\TextInput::make('city')->maxLength(120)->datalist($suggest('city')),
                \Filament\Forms\Components\TextInput::make('country')->maxLength(60)->datalist($suggest('country')),
                \Filament\Forms\Components\TextInput::make('latitude')->numeric()->minValue(-90)->maxValue(90)->step('any'),
                \Filament\Forms\Components\TextInput::make('longitude')->numeric()->minValue(-180)->maxValue(180)->step('any'),
                \Filament\Forms\Components\TextInput::make('geofence_radius_m')->label('On-site radius (m)')->integer()->minValue(20)->maxValue(2000)
                    ->placeholder((string) Store::DEFAULT_GEOFENCE_M)
                    ->helperText('Work that must be done at the store is accepted within this distance of the coordinates above. Blank uses the default (' . Store::DEFAULT_GEOFENCE_M . ' m).'),
                \Filament\Forms\Components\Select::make('timezone')->searchable()
                    ->options(fn () => array_combine(\DateTimeZone::listIdentifiers(), \DateTimeZone::listIdentifiers()))
                    ->placeholder('Company time zone'),
                \Filament\Forms\Components\TextInput::make('sales_area_sqm')->label('Sales area (m²)')->numeric()->minValue(0),
            ]),
        ]);
    }

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
            // WP6.4 (audit H30/H32): the newest sales day per store from the
            // (tenant, store, date) index — not a count of every sales line.
            ->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->addSelect([
                'last_sale_date' => \Illuminate\Support\Facades\DB::table('sales_daily')->selectRaw('MAX(date)')
                    ->whereColumn('sales_daily.tenant_id', 'stores.tenant_id')->whereColumn('sales_daily.store_id', 'stores.id'),
            ]))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('region')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('area')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),

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

                TextColumn::make('last_sale_date')
                    ->label('Last sale')
                    ->date()
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('current_positions_count')
                    ->counts('currentPositions')
                    ->label('Stock positions')
                    ->alignCenter()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('region')
                    ->options(fn () => Store::query()->where('tenant_id', \Filament\Facades\Filament::getTenant()?->id)
                        ->whereNotNull('region')->where('region', '<>', '')->distinct()->orderBy('region')->pluck('region', 'region')->toArray())
                    ->label('Region')
                    ->multiple(),

                SelectFilter::make('city')
                    ->options(fn () => Store::query()->where('tenant_id', \Filament\Facades\Filament::getTenant()?->id) /* WP2.4 explicit tenant scope */
                        ->whereNotNull('city')
                        ->distinct()
                        ->orderBy('city')
                        ->pluck('city', 'city')
                        ->toArray()
                    )
                    ->label('City')
                    ->multiple(),

                SelectFilter::make('country')
                    ->options(fn () => Store::query()->where('tenant_id', \Filament\Facades\Filament::getTenant()?->id) /* WP2.4 explicit tenant scope */
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
                    ->options(fn () => Store::query()->where('tenant_id', \Filament\Facades\Filament::getTenant()?->id) /* WP2.4 explicit tenant scope */
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
                    ->options(fn () => Store::query()->where('tenant_id', \Filament\Facades\Filament::getTenant()?->id) /* WP2.4 explicit tenant scope */
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
                    ->options(fn () => Store::query()->where('tenant_id', \Filament\Facades\Filament::getTenant()?->id) /* WP2.4 explicit tenant scope */
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
            ->actions([\Filament\Actions\EditAction::make()])
            ->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [
            StoreResource\RelationManagers\ZonesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListStores::route('/'),
            'create' => Pages\CreateStore::route('/create'),
            'edit'   => Pages\EditStore::route('/{record}/edit'),
        ];
    }
}
