<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CustomMetricResource\Pages;
use App\Models\CustomMetricDefinition;
use App\Platform\Extensibility\CustomRuleEngine;
use App\Support\Money;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * W10 (WP10.6) — the tenant's own KPIs, typed as formulas on business-level
 * figures, shown on the dashboard under "Your KPIs". Tenant admins only.
 */
class CustomMetricResource extends Resource
{
    protected static ?string $model = CustomMetricDefinition::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-calculator';

    protected static \UnitEnum|string|null $navigationGroup = 'Root Cause';

    protected static ?string $navigationLabel = 'Custom KPIs';

    protected static ?string $modelLabel = 'custom KPI';

    protected static ?int $navigationSort = 61;

    public const UNITS = ['money' => 'Money', 'percent' => 'Percent', 'count' => 'Count', 'days' => 'Days', 'ratio' => 'Ratio'];

    public const EXAMPLES = [
        'Out-of-stock rate'   => 'out_of_stock_positions / stock_positions * 100',
        'Stock turn (weeks)'  => 'stock_value / (revenue_28d / 4)',
        'Revenue at risk, %'  => 'revenue_at_risk / revenue_28d * 100',
    ];

    public static function canAccess(): bool
    {
        // The screen belongs to Root Cause: gone from the menu (and refused) without it.
        if (! \App\Support\Apps\AppGate::allows(\Filament\Facades\Filament::getTenant(), static::class)) {
            return false;
        }

        $user = auth()->user();

        return (bool) ($user && ($user->is_super_admin || $user->is_tenant_admin));
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('tenant_id', Filament::getTenant()?->id);
    }

    public static function prepare(array $data, bool $bumpVersion = false, ?CustomMetricDefinition $record = null): array
    {
        $data['formula'] = trim((string) ($data['formula'] ?? ''));
        $data['expression'] = CustomRuleEngine::compileMetric($data['formula'])['ast'];
        if (empty($data['key']) && ! empty($data['label'])) {
            $base = Str::limit(Str::slug($data['label'], '_'), 50, '') ?: 'kpi';
            $base = preg_match('/^[a-z]/', $base) ? $base : 'k_' . $base;
            $key = $base;
            for ($i = 2; CustomMetricDefinition::where('tenant_id', $data['tenant_id'] ?? null)->where('key', $key)->exists(); $i++) {
                $key = $base . '_' . $i;
            }
            $data['key'] = $key;
        }
        // A changed definition is a new version of the KPI (P1.4 metric layer).
        if ($record !== null && $record->formula !== $data['formula']) {
            $data['version'] = (int) $record->version + 1;
        }

        return $data;
    }

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            Section::make('KPI')->columns(2)->schema([
                TextInput::make('label')->label('Name')->required()->maxLength(120),
                TextInput::make('key')->label('Key')->maxLength(60)->regex('/^[a-z][a-z0-9_]*$/')
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('tenant_id', Filament::getTenant()?->id))
                    ->helperText('Left blank, it is made from the name.')
                    ->disabledOn('edit')->dehydrated(fn (string $operation) => $operation === 'create'),
                Textarea::make('formula')->label('Formula')->required()->rows(2)->columnSpanFull()
                    ->rules([fn () => function (string $attribute, $value, \Closure $fail) {
                        try {
                            CustomRuleEngine::compileMetric((string) $value);
                        } catch (\InvalidArgumentException $e) {
                            $fail($e->getMessage());
                        }
                    }])
                    ->placeholder('out_of_stock_positions / stock_positions * 100')
                    ->helperText('Examples: ' . collect(self::EXAMPLES)->map(fn ($f, $n) => "{$n}: {$f}")->implode(' · ')),
                Select::make('unit')->options(self::UNITS)->default('count')->required(),
                Toggle::make('active')->label('Show on the dashboard')->default(true)->inline(false),
                Textarea::make('description')->label('What it tells you')->rows(2)->columnSpanFull(),
            ]),
            Section::make('Variables you can use')->collapsible()->collapsed()->schema([
                Placeholder::make('vars')->hiddenLabel()->content(CustomRuleResource::variablesHelp('tenant')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $cur = Money::normalize(Filament::getTenant()?->currency);

        return $table
            ->columns([
                TextColumn::make('label')->label('Name')->searchable()->weight('semibold')
                    ->description(fn (CustomMetricDefinition $m) => $m->key . ' · v' . $m->version),
                TextColumn::make('formula')->fontFamily('mono')->limit(70)->wrap(),
                TextColumn::make('value')->label('Value now')->state(function (CustomMetricDefinition $m) use ($cur) {
                    try {
                        return CustomRuleEngine::formatValue(app(CustomRuleEngine::class)->metricValue($m), (string) $m->unit, $cur);
                    } catch (\Throwable) {
                        return 'error';
                    }
                }),
                IconColumn::make('active')->label('On dashboard')->boolean(),
                TextColumn::make('updated_at')->since()->sortable()->toggleable(),
            ])
            ->defaultSort('label')
            ->actions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('No custom KPIs yet')
            ->emptyStateDescription('Define a KPI in your own terms — e.g. "out_of_stock_positions / stock_positions * 100" — and it appears on the dashboard.');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListCustomMetrics::route('/'),
            'create' => Pages\CreateCustomMetric::route('/create'),
            'edit'   => Pages\EditCustomMetric::route('/{record}/edit'),
        ];
    }
}
