<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InvestigationResource\Pages;
use App\Models\Investigation;
use App\Models\Team;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InvestigationResource extends Resource
{
    protected static ?string $model = Investigation::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-magnifying-glass';

    protected static \UnitEnum|string|null $navigationGroup = 'Intelligence';

    protected static ?string $navigationLabel = 'Investigations';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool                                            { return false; }
    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool   { return false; }
    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool { return false; }

    public static function getEloquentQuery(): Builder
    {
        $tenantId = Filament::getTenant()?->id;
        return parent::getEloquentQuery()
            ->where('investigations.tenant_id', $tenantId)
            ->with(['assignedTeam', 'assignedUser']);
    }

    public static function getNavigationBadge(): ?string
    {
        $tenantId = Filament::getTenant()?->id;
        if (!$tenantId) return null;

        $count = Investigation::where('tenant_id', $tenantId)
            ->whereIn('status', [Investigation::STATUS_OPEN, Investigation::STATUS_IN_PROGRESS])
            ->whereIn('priority', [Investigation::PRIORITY_HIGH, Investigation::PRIORITY_CRITICAL])
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('priority')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'critical' => 'danger',
                        'high'     => 'warning',
                        'medium'   => 'info',
                        default    => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('title')
                    ->searchable()
                    ->limit(60)
                    ->wrap(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'open'        => 'warning',
                        'in_progress' => 'info',
                        'resolved'    => 'success',
                        'closed'      => 'gray',
                        default       => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('anomaly_count')
                    ->label('Anomalies')
                    ->sortable()
                    ->alignCenter(),

                TextColumn::make('primary_sku')
                    ->label('SKU')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('assignedTeam.name')
                    ->label('Team')
                    ->placeholder('Unassigned')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('ai_confidence')
                    ->label('Confidence')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'established' => 'success',
                        'probable'    => 'info',
                        'suspected'   => 'warning',
                        default       => 'gray',
                    })
                    ->placeholder('—'),

                TextColumn::make('opened_at')
                    ->label('Opened')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('opened_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'open'        => 'Open',
                        'in_progress' => 'In Progress',
                        'resolved'    => 'Resolved',
                        'closed'      => 'Closed',
                    ])
                    ->default('open'),

                SelectFilter::make('priority')
                    ->options([
                        'critical' => 'Critical',
                        'high'     => 'High',
                        'medium'   => 'Medium',
                        'low'      => 'Low',
                    ]),

                SelectFilter::make('assigned_team_id')
                    ->label('Team')
                    ->options(fn () => Team::where('tenant_id', Filament::getTenant()?->id)
                        ->pluck('name', 'id')
                        ->toArray()),
            ])
            ->actions([
                \Filament\Tables\Actions\Action::make('investigate')
                    ->label('Investigate')
                    ->icon('heroicon-o-magnifying-glass')
                    ->url(fn (Investigation $record) => static::getUrl('investigate', ['record' => $record]))
                    ->color('primary'),
            ])
            ->bulkActions([
                BulkActionGroup::make([]),
            ]);
    }

    public static function getRelationManagers(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'       => Pages\ListInvestigations::route('/'),
            'investigate' => Pages\InvestigateInvestigation::route('/{record}/investigate'),
        ];
    }
}
