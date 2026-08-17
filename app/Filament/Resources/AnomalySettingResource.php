<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AnomalySettingResource\Pages;
use App\Models\AnomalySetting;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AnomalySettingResource extends Resource
{
    protected static ?string $model = AnomalySetting::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static \UnitEnum|string|null $navigationGroup = 'Intelligence';

    protected static ?string $navigationLabel = 'Detection Rules';

    protected static ?int $navigationSort = 2;

    // Only admins can manage detection rules
    public static function canViewAny(): bool
    {
        $user = auth()->user();
        return $user && ($user->is_super_admin || $user->is_tenant_admin);
    }

    public static function canCreate(): bool    { return false; }
    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool { return false; }

    // Auto-seed the 10 rules if missing when listing
    public static function getEloquentQuery(): Builder
    {
        $tenantId = Filament::getTenant()?->id;
        if ($tenantId) {
            AnomalySetting::seedForTenant($tenantId);
        }
        return parent::getEloquentQuery();
    }

    public static function form(Schema $form): Schema
    {
        return $form->components([
            Placeholder::make('rule_label')
                ->label('Rule')
                ->content(fn (?AnomalySetting $record) => $record?->getRuleLabel() ?? ''),

            Placeholder::make('rule_description')
                ->label('Description')
                ->content(fn (?AnomalySetting $record) => $record?->getRuleDescription() ?? ''),

            Toggle::make('enabled')
                ->label('Enabled')
                ->helperText('Disable to stop this rule from firing during detection runs.')
                ->required(),

            TextInput::make('thresholds.pct')
                ->label('Deviation threshold (%)')
                ->helperText('Flag anomalies that deviate by more than this percentage.')
                ->numeric()
                ->minValue(1)
                ->maxValue(100)
                ->suffix('%')
                ->visible(fn (?AnomalySetting $record) => $record && in_array($record->rule_type, [
                    'sales_spike', 'sales_drop', 'price_anomaly',
                    'receiving_discrepancy', 'inventory_shrinkage', 'store_outlier',
                ])),

            TextInput::make('thresholds.days')
                ->label('Lookback period (days)')
                ->helperText('Number of days to look back when evaluating this rule.')
                ->numeric()
                ->minValue(1)
                ->suffix('days')
                ->visible(fn (?AnomalySetting $record) => $record && in_array($record->rule_type, [
                    'sales_spike', 'sales_drop', 'dead_stock', 'store_outlier',
                ])),

            Placeholder::make('no_thresholds')
                ->label('Thresholds')
                ->content('This rule has no configurable thresholds — it fires whenever the condition is met.')
                ->visible(fn (?AnomalySetting $record) => $record && in_array($record->rule_type, [
                    'stockout_risk', 'po_overdue', 'margin_erosion',
                ])),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('rule_label')
                    ->label('Rule')
                    ->getStateUsing(fn (AnomalySetting $record) => $record->getRuleLabel())
                    ->searchable(query: fn (Builder $query, string $search) => $query->where('rule_type', 'like', "%{$search}%"))
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('rule_type', $direction)),

                TextColumn::make('rule_description')
                    ->label('Description')
                    ->getStateUsing(fn (AnomalySetting $record) => $record->getRuleDescription())
                    ->wrap()
                    ->limit(80),

                TextColumn::make('severity')
                    ->label('Severity')
                    ->getStateUsing(fn (AnomalySetting $record) => $record->getDefaultSeverity())
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'high'   => 'danger',
                        'medium' => 'warning',
                        'low'    => 'info',
                        default  => 'gray',
                    }),

                TextColumn::make('thresholds_summary')
                    ->label('Thresholds')
                    ->getStateUsing(fn (AnomalySetting $record) => $record->getThresholdsSummary()),

                ToggleColumn::make('enabled')
                    ->label('Active')
                    ->sortable(),
            ])
            ->defaultSort('rule_type')
            ->paginated(false);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAnomalySettings::route('/'),
            'edit'  => Pages\EditAnomalySetting::route('/{record}/edit'),
        ];
    }
}
