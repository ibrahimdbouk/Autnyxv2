<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CustomRuleResource\Pages;
use App\Models\Anomaly;
use App\Models\CustomRuleDefinition;
use App\Platform\Extensibility\CustomRuleEngine;
use App\Platform\Extensibility\CustomVariables;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * W10 (WP10.6) — the tenant's own detection rules, typed as formulas on store ×
 * SKU data. Each active rule runs with the nightly detection; every position
 * where it holds becomes an anomaly with the rule's label and severity, and
 * flows into investigations like any built-in rule. Tenant admins only.
 */
class CustomRuleResource extends Resource
{
    protected static ?string $model = CustomRuleDefinition::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-variable';

    protected static \UnitEnum|string|null $navigationGroup = 'Intelligence';

    protected static ?string $navigationLabel = 'Custom rules';

    protected static ?string $modelLabel = 'custom rule';

    protected static ?int $navigationSort = 60;

    public const EXAMPLES = [
        'Fast seller about to run out' => 'units_7d >= 20 and days_of_cover < 3',
        'Stock but no sales for 30 days' => 'on_hand > 0 and days_since_last_sale >= 30',
        'Selling below cost' => 'selling_price < unit_cost',
        'Sales fell by half' => 'units_prev_28d >= 20 and trend_pct <= -50',
    ];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user && ($user->is_super_admin || $user->is_tenant_admin));
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('tenant_id', Filament::getTenant()?->id);
    }

    /** Formula text → the stored AST (and a key from the label on create). */
    public static function prepare(array $data): array
    {
        $data['formula'] = trim((string) ($data['formula'] ?? ''));
        $data['condition'] = CustomRuleEngine::compileCondition($data['formula'])['ast'];
        $impact = trim((string) ($data['impact_formula'] ?? ''));
        $data['impact_formula'] = $impact !== '' ? $impact : null;
        $data['impact'] = $impact !== '' ? CustomRuleEngine::compileRule($impact)['ast'] : null;
        if (empty($data['key']) && ! empty($data['label'])) {
            $base = Str::limit(Str::slug($data['label'], '_'), 50, '') ?: 'rule';
            $base = preg_match('/^[a-z]/', $base) ? $base : 'r_' . $base;
            $key = $base;
            for ($i = 2; CustomRuleDefinition::where('tenant_id', $data['tenant_id'] ?? null)->where('key', $key)->exists(); $i++) {
                $key = $base . '_' . $i;
            }
            $data['key'] = $key;
        }

        return $data;
    }

    private static function formulaRule(bool $condition): \Closure
    {
        return fn () => function (string $attribute, $value, \Closure $fail) use ($condition) {
            if ($value === null || trim((string) $value) === '') {
                return;
            }
            try {
                $condition ? CustomRuleEngine::compileCondition((string) $value) : CustomRuleEngine::compileRule((string) $value);
            } catch (\InvalidArgumentException $e) {
                $fail($e->getMessage());
            }
        };
    }

    public static function variablesHelp(string $grain): HtmlString
    {
        $rows = collect($grain === 'tenant' ? CustomVariables::TENANT : CustomVariables::POSITION)
            ->map(fn ($v, $k) => '<tr><td style="padding:2px 12px 2px 0;font-family:ui-monospace,monospace;">' . e($k) . '</td><td style="padding:2px 0;color:#6b7280;">' . e($v[0]) . '</td></tr>')
            ->implode('');

        return new HtmlString('<p style="margin:0 0 .5rem;color:#6b7280;">Operators: + − × ÷ ( ), &gt; &gt;= &lt; &lt;= = !=, and, or, not. Sales windows end on your latest sales date.</p><table style="font-size:.85rem;">' . $rows . '</table>');
    }

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            Section::make('Rule')->columns(2)->schema([
                TextInput::make('label')->label('Name')->required()->maxLength(120)
                    ->helperText('Shown on every anomaly this rule raises.'),
                TextInput::make('key')->label('Key')->maxLength(60)
                    ->regex('/^[a-z][a-z0-9_]*$/')
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('tenant_id', Filament::getTenant()?->id))
                    ->helperText('Lower-case letters, digits, _. Left blank, it is made from the name.')
                    ->disabledOn('edit')->dehydrated(fn (string $operation) => $operation === 'create'),
                Textarea::make('formula')->label('Flag a store × SKU when')->required()->rows(2)->columnSpanFull()
                    ->rules([self::formulaRule(true)])
                    ->placeholder('units_7d >= 20 and days_of_cover < 3')
                    ->helperText(new HtmlString('Examples: ' . collect(self::EXAMPLES)->map(fn ($f, $n) => e($n) . ' — <code>' . e($f) . '</code>')->implode('; '))),
                Select::make('severity')->options([
                    CustomRuleDefinition::SEVERITY_INFO     => 'Info (low)',
                    CustomRuleDefinition::SEVERITY_WARNING  => 'Warning (medium)',
                    CustomRuleDefinition::SEVERITY_CRITICAL => 'Critical (high)',
                ])->default(CustomRuleDefinition::SEVERITY_WARNING)->required(),
                Toggle::make('active')->label('Run with nightly detection')->default(true)->inline(false),
            ]),
            Section::make('What a hit is worth (optional)')->columns(2)->schema([
                Textarea::make('impact_formula')->label('Money at stake per hit')->rows(2)->columnSpanFull()
                    ->rules([self::formulaRule(false)])
                    ->placeholder('avg_daily_units_28d * selling_price * 7')
                    ->helperText('A number in your currency. Left blank, hits carry no money figure.'),
                Select::make('value_type')->label('Kind of money')->options(CustomRuleEngine::VALUE_TYPES)
                    ->default(\App\Support\Detection\ValueModel::DATA_QUALITY)->columnSpanFull(),
                Textarea::make('description')->label('Why this matters / what to do')->rows(2)->columnSpanFull(),
            ]),
            Section::make('Variables you can use')->collapsible()->collapsed()->schema([
                Placeholder::make('vars')->hiddenLabel()->content(self::variablesHelp('position')),
            ]),
        ]);
    }

    public static function openHits(CustomRuleDefinition $r): int
    {
        return Anomaly::where('tenant_id', $r->tenant_id)->where('rule_type', 'custom_rule')
            ->where('context->custom_key', $r->key)->active()->count();
    }

    public static function previewAction(): Action
    {
        return Action::make('preview')
            ->label('Test on my data')
            ->icon('heroicon-o-beaker')
            ->color('info')
            ->action(function (CustomRuleDefinition $record) {
                try {
                    $p = app(CustomRuleEngine::class)->preview((int) $record->tenant_id, (string) $record->formula, $record->impact_formula);
                } catch (\InvalidArgumentException $e) {
                    Notification::make()->title('The formula does not run')->body($e->getMessage())->danger()->send();

                    return;
                }
                $stores = \App\Models\Store::where('tenant_id', $record->tenant_id)->pluck('name', 'id');
                $cur = Money::normalize(Filament::getTenant()?->currency);
                $lines = collect($p['samples'])->map(fn ($s) => 'SKU ' . $s['sku'] . ' at ' . ($stores[$s['store_id']] ?? 'all stores') . ' — '
                    . collect($s['inputs'])->map(fn ($v, $k) => $k . ' ' . ($v === null ? '—' : round((float) $v, 2)))->implode(', ')
                    . ($s['impact'] !== null ? ' — ' . Money::compact((float) $s['impact'], $cur) : ''))->implode("\n");
                Notification::make()
                    ->title(number_format($p['count']) . ' store × SKU positions match today'
                        . ($p['impact_total'] > 0 ? ' · ' . Money::compact($p['impact_total'], $cur) . ' at stake' : ''))
                    ->body($p['count'] > 0 ? $lines : 'Nothing matches on your current data.')
                    ->info()->persistent()->send();
            });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')->label('Name')->searchable()->weight('semibold')
                    ->description(fn (CustomRuleDefinition $r) => $r->key),
                TextColumn::make('formula')->fontFamily('mono')->limit(70)->wrap(),
                TextColumn::make('severity')->badge()->color(fn (string $state) => match ($state) {
                    'critical' => 'danger', 'warning' => 'warning', default => 'gray'}),
                TextColumn::make('open_hits')->label('Open anomalies')->state(fn (CustomRuleDefinition $r) => self::openHits($r))->numeric(),
                IconColumn::make('active')->boolean(),
                TextColumn::make('updated_at')->since()->sortable()->toggleable(),
            ])
            ->defaultSort('label')
            ->actions([self::previewAction(), EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('No custom rules yet')
            ->emptyStateDescription('Write a rule in your own terms — e.g. "units_7d >= 20 and days_of_cover < 3" — and it runs with every nightly detection.');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListCustomRules::route('/'),
            'create' => Pages\CreateCustomRule::route('/create'),
            'edit'   => Pages\EditCustomRule::route('/{record}/edit'),
        ];
    }
}
