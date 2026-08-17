<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AnomalyResource\Pages;
use App\Models\Anomaly;
use App\Models\AnomalySetting;
use App\Services\Anomaly\BaselineCalculatorService;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AnomalyResource extends Resource
{
    protected static ?string $model = Anomaly::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static \UnitEnum|string|null $navigationGroup = 'Intelligence';

    protected static ?string $navigationLabel = 'Anomalies';

    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool    { return false; }
    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool   { return false; }
    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool { return false; }

    public static function getEloquentQuery(): Builder
    {
        // Default: only show open (non-dismissed) anomalies
        return parent::getEloquentQuery()->whereNull('dismissed_at');
    }

    public static function getNavigationBadge(): ?string
    {
        $tenantId = \Filament\Facades\Filament::getTenant()?->id;
        if (!$tenantId) return null;

        $count = Anomaly::where('tenant_id', $tenantId)
            ->whereNull('dismissed_at')
            ->where('severity', 'high')
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        $ruleOptions = collect(AnomalySetting::RULES)->map(fn ($r) => $r['label'])->toArray();

        return $table
            ->columns([
                TextColumn::make('severity')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'high'   => 'danger',
                        'medium' => 'warning',
                        'low'    => 'info',
                        default  => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('rule_type')
                    ->label('Rule')
                    ->formatStateUsing(fn (string $state): string => AnomalySetting::RULES[$state]['label'] ?? $state)
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('description')
                    ->wrap()
                    ->limit(120),

                TextColumn::make('investigation_status')
                    ->label('Investigation')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'investigating'     => 'Investigating',
                        'cause_established' => 'Cause Established',
                        'action_taken'      => 'Action Taken',
                        'resolved'          => 'Resolved',
                        'unresolved'        => 'Unresolved',
                        default             => 'Not Started',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'investigating'     => 'warning',
                        'cause_established' => 'info',
                        'action_taken'      => 'warning',
                        'resolved'          => 'success',
                        'unresolved'        => 'danger',
                        default             => 'gray',
                    }),

                TextColumn::make('detected_at')
                    ->label('Detected')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('severity', 'desc')
            ->filters([
                SelectFilter::make('severity')
                    ->options([
                        'high'   => 'High',
                        'medium' => 'Medium',
                        'low'    => 'Low',
                    ]),

                SelectFilter::make('rule_type')
                    ->label('Rule')
                    ->options($ruleOptions),

                Filter::make('dismissed')
                    ->label('Show dismissed')
                    ->query(fn (Builder $query) => $query->withoutGlobalScopes()->whereNotNull('dismissed_at'))
                    ->toggle(),
            ])
            ->actions([
                \Filament\Tables\Actions\Action::make('investigate')
                    ->label('Investigate')
                    ->icon('heroicon-o-cpu-chip')
                    ->color('primary')
                    ->url(fn (Anomaly $record): string => static::getUrl('investigate', ['record' => $record]))
                    ->visible(fn (Anomaly $record) => !$record->isDismissed()),

                \Filament\Tables\Actions\Action::make('dismiss')
                    ->label('Dismiss')
                    ->icon('heroicon-o-x-mark')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (Anomaly $record) => !$record->isDismissed())
                    ->action(function (Anomaly $record) {
                        $dismissedAt = now();

                        // False-positive feedback: dismiss within 10 min of detection
                        if ($record->detected_at && $dismissedAt->diffInMinutes($record->detected_at) < 10) {
                            app(BaselineCalculatorService::class)
                                ->recordFalsePositive($record->tenant_id, $record->rule_type, $record->sku);
                        }

                        $record->update([
                            'dismissed_at' => $dismissedAt,
                            'dismissed_by' => auth()->id(),
                        ]);
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'       => Pages\ListAnomalies::route('/'),
            'investigate' => Pages\InvestigateAnomaly::route('/{record}/investigate'),
        ];
    }
}
