<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PlanningExceptionResource\Pages;
use App\Models\PlanningException;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * PlanningExceptionResource — a read-only review panel for F&R exceptions ingested
 * from the tenant's planning system (RELEX / Blue Yonder / Slimstock). First-class
 * ones (upstream-only risks) also appear in the action queue as anomalies; the ones
 * that corroborate an Autnyx anomaly are attached to it; unmatched corroboration
 * signals live here (status = open) without cluttering the queue.
 *
 * See claude/api-integration-library.md.
 */
class PlanningExceptionResource extends Resource
{
    protected static ?string $model = PlanningException::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static \UnitEnum|string|null $navigationGroup = 'Data';

    protected static ?string $navigationLabel = 'Planning Exceptions';

    protected static ?int $navigationSort = 5;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user && ($user->is_super_admin || $user->is_tenant_admin));
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('tenant_id', Filament::getTenant()?->id);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('source')->badge()->color('gray')->sortable(),
                TextColumn::make('sku')->searchable()->weight('semibold'),
                TextColumn::make('store_id')->label('Store')->placeholder('Chain')->toggleable(),
                TextColumn::make('category')->badge()->color('info'),
                TextColumn::make('disposition')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === PlanningException::DISPOSITION_FIRST_CLASS ? 'First-class' : 'Corroboration')
                    ->color(fn (string $state) => $state === PlanningException::DISPOSITION_FIRST_CLASS ? 'warning' : 'gray'),
                TextColumn::make('severity')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'high'   => 'danger',
                        'medium' => 'warning',
                        default  => 'info',
                    }),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        PlanningException::STATUS_MATCHED  => 'Linked',
                        PlanningException::STATUS_RESOLVED => 'Cleared',
                        default                            => 'Review',
                    })
                    ->color(fn (string $state) => match ($state) {
                        PlanningException::STATUS_MATCHED  => 'success',
                        PlanningException::STATUS_RESOLVED => 'gray',
                        default                            => 'warning',
                    }),
                TextColumn::make('message')->limit(60)->wrap()->toggleable(),
                TextColumn::make('occurred_at')->date()->placeholder('—')->sortable()->toggleable(),
                TextColumn::make('updated_at')->label('Ingested')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('source')
                    ->label('Source')
                    ->multiple()
                    ->options(fn () => PlanningException::query()
                        ->where('tenant_id', Filament::getTenant()?->id)
                        ->whereNotNull('source')->distinct()
                        ->orderBy('source')->pluck('source', 'source')->toArray()),

                SelectFilter::make('category')
                    ->label('Category')
                    ->multiple()
                    ->options(fn () => PlanningException::query()
                        ->where('tenant_id', Filament::getTenant()?->id)
                        ->whereNotNull('category')->distinct()
                        ->orderBy('category')->pluck('category', 'category')->toArray()),

                SelectFilter::make('store_id')
                    ->label('Store')
                    ->multiple()
                    ->options(fn () => PlanningException::query()
                        ->where('tenant_id', Filament::getTenant()?->id)
                        ->whereNotNull('store_id')->distinct()
                        ->orderBy('store_id')->pluck('store_id', 'store_id')->toArray()),

                SelectFilter::make('disposition')
                    ->label('Disposition')
                    ->options([
                        PlanningException::DISPOSITION_FIRST_CLASS => 'First-class',
                        'corroboration'                           => 'Corroboration',
                    ]),

                SelectFilter::make('severity')
                    ->label('Severity')
                    ->multiple()
                    ->options([
                        'high'   => 'High',
                        'medium' => 'Medium',
                        'low'    => 'Low',
                    ]),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'open'                             => 'Review',
                        PlanningException::STATUS_MATCHED  => 'Linked',
                        PlanningException::STATUS_RESOLVED => 'Cleared',
                    ]),

                Filter::make('occurred_at')
                    ->label('Occurred Date')
                    ->form([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'],  fn (Builder $q, $d) => $q->whereDate('occurred_at', '>=', $d))
                            ->when($data['until'], fn (Builder $q, $d) => $q->whereDate('occurred_at', '<=', $d));
                    }),
            ])
            ->defaultSort('updated_at', 'desc')
            ->emptyStateHeading('No planning exceptions ingested')
            ->emptyStateDescription('Connect a "Planning Exceptions" feed on an API connection to ingest your F&R system\'s own alerts.')
            ->emptyStateIcon('heroicon-o-inbox-arrow-down');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPlanningExceptions::route('/'),
        ];
    }
}
