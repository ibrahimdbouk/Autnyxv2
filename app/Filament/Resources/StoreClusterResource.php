<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StoreClusterResource\Pages;
use App\Models\StoreCluster;
use App\Platform\Intelligence\Clustering\ClusterService;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Store Clustering (tenant panel, Data group).
 *
 * Shows the recommended store peer groups — the "why" (format + region rationale)
 * and the numbers (stores, 90-day revenue, SKUs) — and lets an admin amend them:
 * rename, move stores, add or remove groups. Any edit marks the tenant customised
 * so the nightly rebuild leaves their grouping alone until they reset.
 *
 * Operates on the configured strategy's method (attribute today); a store belongs
 * to exactly one cluster, enforced on save.
 */
class StoreClusterResource extends Resource
{
    protected static ?string $model = StoreCluster::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-rectangle-group';

    protected static \UnitEnum|string|null $navigationGroup = 'Data';

    protected static ?string $navigationLabel = 'Store Clustering';

    protected static ?string $modelLabel = 'store cluster';

    protected static ?string $pluralModelLabel = 'store clusters';

    protected static ?int $navigationSort = 11;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tenant_id', Filament::getTenant()?->id)
            ->where('method', config('clustering.strategy', 'attribute'))
            ->where('objective', StoreCluster::OBJECTIVE_GENERAL)
            ->withCount('stores');
    }

    protected static function isAdmin(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->is_tenant_admin || $user?->is_super_admin);
    }

    public static function canCreate(): bool
    {
        return static::isAdmin();
    }

    public static function canEdit(Model $record): bool
    {
        return static::isAdmin();
    }

    public static function canDelete(Model $record): bool
    {
        return static::isAdmin();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Cluster')
                ->description('A store group and its members. A store belongs to one cluster — adding it here removes it from any other.')
                ->schema([
                    TextInput::make('label')
                        ->label('Name')
                        ->required()
                        ->maxLength(255),

                    Select::make('stores')
                        ->label('Member stores')
                        ->relationship(
                            'stores',
                            'name',
                            modifyQueryUsing: fn (Builder $query) => $query
                                ->where('stores.tenant_id', Filament::getTenant()?->id)
                                ->orderBy('name'),
                        )
                        ->multiple()
                        ->preload()
                        ->searchable(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')
                    ->label('Cluster')
                    ->searchable()
                    ->description(fn (StoreCluster $record) => $record->rationale()),

                TextColumn::make('definition')
                    ->label('Defined by')
                    ->badge()
                    ->color('gray')
                    ->state(fn (StoreCluster $record) => $record->params
                        ? (($record->params['format'] ?? '—') . ' · ' . ($record->params['region'] ?? '—'))
                        : 'Custom'),

                TextColumn::make('stores_count')
                    ->label('Stores')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('revenue')
                    ->label('90-day revenue')
                    ->alignEnd()
                    ->state(fn (StoreCluster $record) => static::money($record->metrics()['revenue'])),

                TextColumn::make('avg_revenue')
                    ->label('Avg / store')
                    ->alignEnd()
                    ->toggleable()
                    ->state(fn (StoreCluster $record) => static::money($record->metrics()['avg_revenue'])),

                TextColumn::make('units')
                    ->label('90-day units')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->state(fn (StoreCluster $record) => number_format($record->metrics()['units'])),

                TextColumn::make('skus')
                    ->label('SKUs')
                    ->alignCenter()
                    ->toggleable()
                    ->state(fn (StoreCluster $record) => number_format($record->metrics()['skus'])),
            ])
            ->defaultSort('label')
            ->actions([
                EditAction::make(),
                DeleteAction::make()
                    ->after(fn () => app(ClusterService::class)->markCustomised(Filament::getTenant()?->id ?? 0)),
            ]);
    }

    /** Format an amount in the current tenant's currency, tolerating no tenant context. */
    protected static function money(float $amount): string
    {
        return Filament::getTenant()?->money($amount) ?? number_format($amount, 2);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListStoreClusters::route('/'),
            'create' => Pages\CreateStoreCluster::route('/create'),
            'edit'   => Pages\EditStoreCluster::route('/{record}/edit'),
        ];
    }
}
