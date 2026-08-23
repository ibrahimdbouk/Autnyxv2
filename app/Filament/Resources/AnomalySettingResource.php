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
    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool   { return auth()->user()?->canChangeAnomalyThresholds() ?? false; }
    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool { return false; }

    // Auto-seed all rules if missing when listing
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
        // Rules that expose each threshold type
        $pctRules = [
            'sales_spike', 'sales_drop', 'demand_seasonality_breach',
            'cannibalization_signal', 'return_rate_spike', 'channel_mix_shift',
            'inventory_shrinkage', 'receiving_discrepancy', 'supplier_lead_time_drift',
            'cost_spike', 'price_anomaly', 'revenue_concentration_risk', 'slow_moving_capital',
            'store_outlier',
        ];

        $daysRules = [
            'sales_spike', 'sales_drop', 'cannibalization_signal',
            'return_rate_spike', 'channel_mix_shift', 'dead_stock',
            'reorder_point_staleness', 'revenue_concentration_risk',
            'slow_moving_capital', 'import_frequency_gap', 'location_proliferation',
            'store_outlier', 'po_late_receipt',
        ];

        // Rules whose noise is gated by a tied-up-value floor (thresholds.min_value).
        $minValueRules = ['slow_moving_capital', 'overstock', 'phantom_inventory', 'receiving_discrepancy'];

        // Rules whose noise is gated by an estimated-revenue-impact floor (thresholds.min_revenue).
        $minRevenueRules = ['sales_spike', 'sales_drop', 'stockout_risk', 'cannibalization_signal'];

        $noThresholdRules = [
            'safety_stock_breach', 'negative_inventory',
            'multi_location_imbalance', 'po_overdue', 'margin_erosion',
            'discount_signal', 'duplicate_transaction_ids', 'sku_master_drift',
        ];

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
                ->maxValue(500)
                ->suffix('%')
                ->visible(fn (?AnomalySetting $record) => $record && in_array($record->rule_type, $pctRules)),

            TextInput::make('thresholds.days')
                ->label('Lookback period (days)')
                ->helperText('Number of days to look back when evaluating this rule.')
                ->numeric()
                ->minValue(1)
                ->suffix('days')
                ->visible(fn (?AnomalySetting $record) => $record && in_array($record->rule_type, $daysRules)),

            TextInput::make('thresholds.days_cover')
                ->label('Days of cover threshold')
                ->helperText('Flag when on-hand stock exceeds this many days of demand at the recent rate.')
                ->numeric()
                ->minValue(1)
                ->suffix('days')
                ->visible(fn (?AnomalySetting $record) => $record && $record->rule_type === 'overstock'),

            TextInput::make('thresholds.min_value')
                ->label('Minimum tied-up value ($)')
                ->helperText('Materiality floor: only flag when the inventory/shortfall value (qty × unit cost) exceeds this amount. Raise it to shorten the list, lower it for wider recall.')
                ->numeric()
                ->minValue(0)
                ->prefix('$')
                ->visible(fn (?AnomalySetting $record) => $record && in_array($record->rule_type, $minValueRules)),

            TextInput::make('thresholds.min_revenue')
                ->label('Minimum revenue impact ($)')
                ->helperText('Materiality floor: only flag when the estimated revenue at risk exceeds this amount (0 = surface every hit, ranked by impact). Raise it to cut low-value noise.')
                ->numeric()
                ->minValue(0)
                ->prefix('$')
                ->visible(fn (?AnomalySetting $record) => $record && in_array($record->rule_type, $minRevenueRules)),

            Placeholder::make('no_thresholds')
                ->label('Thresholds')
                ->content('This rule has no configurable thresholds — it fires whenever the condition is met.')
                ->visible(fn (?AnomalySetting $record) => $record && in_array($record->rule_type, $noThresholdRules)),
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
