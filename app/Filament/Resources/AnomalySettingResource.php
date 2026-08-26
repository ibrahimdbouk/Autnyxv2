<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AnomalySettingResource\Pages;
use App\Models\AnomalySetting;
use App\Services\Anomaly\ThresholdRecommenderService;
use App\Services\Anomaly\ThresholdTuningService;
use App\Support\Money;
use Filament\Actions\Action;
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

    /** Memoised B3 recommendations for the active tenant (computed once per request). */
    private static ?array $recCache = null;

    private static function recommendations(): array
    {
        if (self::$recCache !== null) return self::$recCache;

        $tenantId = Filament::getTenant()?->id;
        return self::$recCache = $tenantId
            ? app(ThresholdRecommenderService::class)->recommendForTenant($tenantId)
            : ['rules' => [], 'notes' => []];
    }

    /** Memoised B7 outcome-driven tuning suggestions for the active tenant. */
    private static ?array $tuneCache = null;

    private static function tuningSuggestions(): array
    {
        if (self::$tuneCache !== null) return self::$tuneCache;

        $tenantId = Filament::getTenant()?->id;
        return self::$tuneCache = $tenantId
            ? app(ThresholdTuningService::class)->suggestionsForTenant($tenantId)
            : [];
    }

    private static function recommendedModalNote(AnomalySetting $record): string
    {
        $spec = self::recommendations()['rules'][$record->rule_type] ?? null;
        if ($spec === null) return '';

        $val = Money::format($spec['recommended'], Filament::getTenant()?->currencyCode(), 0);
        $key = $spec['key'] === 'min_revenue' ? 'revenue-impact floor' : 'tied-up-value floor';

        return "Set the {$key} to {$val}. {$spec['rationale']} You can fine-tune it afterwards on this rule.";
    }

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
        // Currency symbol for this tenant, for money-valued threshold fields.
        $currencySymbol = \App\Support\Money::symbol(Filament::getTenant()?->currencyCode());

        // Rules that expose each threshold type
        $pctRules = [
            'sales_spike', 'sales_drop', 'demand_seasonality_breach',
            'cannibalization_signal', 'return_rate_spike', 'channel_mix_shift',
            'inventory_shrinkage', 'receiving_discrepancy', 'supplier_lead_time_drift',
            'cost_spike', 'price_anomaly', 'revenue_concentration_risk', 'slow_moving_capital',
            'store_outlier', 'demand_erosion', 'supplier_fill_rate', 'demand_forecast_break',
        ];

        $daysRules = [
            'sales_spike', 'sales_drop', 'cannibalization_signal',
            'return_rate_spike', 'channel_mix_shift', 'dead_stock',
            'reorder_point_staleness', 'revenue_concentration_risk',
            'slow_moving_capital', 'import_frequency_gap', 'location_proliferation',
            'store_outlier', 'po_late_receipt', 'demand_erosion',
        ];

        // Rules whose noise is gated by a tied-up-value floor (thresholds.min_value).
        $minValueRules = ['slow_moving_capital', 'overstock', 'phantom_inventory', 'receiving_discrepancy',
            'cumulative_shrink', 'supplier_fill_rate'];

        // Rules whose noise is gated by an estimated-revenue-impact floor (thresholds.min_revenue).
        $minRevenueRules = ['sales_spike', 'sales_drop', 'stockout_risk', 'cannibalization_signal', 'demand_erosion', 'demand_seasonality_breach', 'demand_forecast_break'];

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
                ->prefix($currencySymbol)
                ->visible(fn (?AnomalySetting $record) => $record && in_array($record->rule_type, $minValueRules)),

            TextInput::make('thresholds.min_revenue')
                ->label('Minimum revenue impact ($)')
                ->helperText('Materiality floor: only flag when the estimated revenue at risk exceeds this amount (0 = surface every hit, ranked by impact). Raise it to cut low-value noise.')
                ->numeric()
                ->minValue(0)
                ->prefix($currencySymbol)
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
                    ->getStateUsing(fn (AnomalySetting $record) => $record->getThresholdsSummary(Filament::getTenant()?->currencyCode())),

                TextColumn::make('recommended')
                    ->label('Recommended floor')
                    ->badge()
                    ->color('gray')
                    ->tooltip(fn (AnomalySetting $record) => self::recommendations()['rules'][$record->rule_type]['rationale'] ?? null)
                    ->getStateUsing(function (AnomalySetting $record) {
                        $spec = self::recommendations()['rules'][$record->rule_type] ?? null;
                        if ($spec === null) return '—';
                        return Money::format($spec['recommended'], Filament::getTenant()?->currencyCode(), 0);
                    }),

                TextColumn::make('learned')
                    ->label('Learned (from outcomes)')
                    ->badge()
                    ->color('warning')
                    ->tooltip(fn (AnomalySetting $record) => self::tuningSuggestions()[$record->rule_type]['reason'] ?? null)
                    ->getStateUsing(function (AnomalySetting $record) {
                        $s = self::tuningSuggestions()[$record->rule_type] ?? null;
                        if ($s === null) return '—';
                        $val = $s['key'] === 'pct'
                            ? '±' . (int) $s['suggested'] . '%'
                            : Money::format($s['suggested'], Filament::getTenant()?->currencyCode(), 0);
                        return $val . ' (' . round($s['fp_rate'] * 100) . '% FP)';
                    }),

                ToggleColumn::make('enabled')
                    ->label('Active')
                    ->sortable(),
            ])
            ->defaultSort('rule_type')
            ->paginated(false)
            ->actions([
                Action::make('apply_learned')
                    ->label('Apply learned')
                    ->icon('heroicon-o-academic-cap')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Apply learned adjustment')
                    ->modalDescription(function (AnomalySetting $record) {
                        $s = self::tuningSuggestions()[$record->rule_type] ?? null;
                        if ($s === null) return '';
                        $cur = $s['key'] === 'pct' ? '±' . (int) $s['current'] . '%' : Money::format($s['current'], Filament::getTenant()?->currencyCode(), 0);
                        $new = $s['key'] === 'pct' ? '±' . (int) $s['suggested'] . '%' : Money::format($s['suggested'], Filament::getTenant()?->currencyCode(), 0);
                        return "{$s['reason']} Change {$cur} → {$new}. You can fine-tune it afterwards.";
                    })
                    ->visible(function (AnomalySetting $record) {
                        if (! (auth()->user()?->canChangeAnomalyThresholds() ?? false)) return false;
                        return isset(self::tuningSuggestions()[$record->rule_type]);
                    })
                    ->action(function (AnomalySetting $record) {
                        $s = self::tuningSuggestions()[$record->rule_type] ?? null;
                        if ($s === null) return;
                        $record->update([
                            'thresholds' => app(ThresholdTuningService::class)->applyTo($record, $s),
                        ]);
                    }),

                Action::make('use_recommended')
                    ->label('Use recommended')
                    ->icon('heroicon-o-sparkles')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalDescription(fn (AnomalySetting $record) => self::recommendedModalNote($record))
                    ->visible(function (AnomalySetting $record) {
                        if (! (auth()->user()?->canChangeAnomalyThresholds() ?? false)) return false;
                        $spec = self::recommendations()['rules'][$record->rule_type] ?? null;
                        if ($spec === null) return false;
                        // Only offer when the recommendation differs from the current value.
                        $current = $record->getEffectiveThresholds()[$spec['key']] ?? null;
                        return (float) $current !== (float) $spec['recommended'];
                    })
                    ->action(function (AnomalySetting $record) {
                        $spec = self::recommendations()['rules'][$record->rule_type] ?? null;
                        if ($spec === null) return;
                        $record->update([
                            'thresholds' => array_merge($record->thresholds ?? [], [$spec['key'] => $spec['recommended']]),
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
            'index' => Pages\ListAnomalySettings::route('/'),
            'edit'  => Pages\EditAnomalySetting::route('/{record}/edit'),
        ];
    }
}
