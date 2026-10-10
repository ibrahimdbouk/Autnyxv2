<?php

namespace App\Filament\Ops\Pages;

use App\Filament\Ops\Widgets\UsageStats;
use App\Models\Tenant;
use App\Models\UsageMeter;
use App\Services\Assortment\AssortmentUsage;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * v2.1 — usage per tenant per month, from the platform's usage meters: the
 * basis for pricing. A native Filament table (filter by month and app, grouped
 * by tenant) with a stats widget on top that follows the month picked.
 */
class Usage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-chart-pie';

    protected static ?string $navigationLabel = 'Usage';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'usage';

    protected string $view = 'filament.ops.usage.index';

    public function getTitle(): string
    {
        return 'Usage';
    }

    public function period(): string
    {
        $p = $this->tableFilters['period']['value'] ?? null;

        return is_string($p) && preg_match('/^\d{4}-\d{2}$/', $p) ? $p : now()->format('Y-m');
    }

    /** @return array<string,string> */
    public static function periods(): array
    {
        $all = UsageMeter::query()->distinct()->orderByDesc('period')->pluck('period')->push(now()->format('Y-m'))->unique()->sortDesc();

        return $all->mapWithKeys(fn ($p) => [$p => \Carbon\Carbon::createFromFormat('Y-m', $p)->format('F Y')])->all();
    }

    public static function metricLabel(string $app, string $metric): string
    {
        return $app === Tenant::APP_ASSORTMENT ? (AssortmentUsage::METRICS[$metric] ?? Str::headline($metric)) : Str::headline($metric);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(UsageMeter::query()->with('tenant:id,name'))
            ->columns([
                TextColumn::make('tenant.name')->label('Tenant')->searchable(),
                TextColumn::make('app')->label('App')->badge()
                    ->formatStateUsing(fn (string $state) => Tenant::APP_LABELS[$state] ?? Str::headline($state)),
                TextColumn::make('metric')->label('What')->formatStateUsing(fn (string $state, UsageMeter $record) => static::metricLabel($record->app, $state)),
                TextColumn::make('count')->label('Count')->numeric()->alignEnd()->sortable(),
                TextColumn::make('updated_at')->label('Updated')->since()->color('gray'),
            ])
            ->filters([
                SelectFilter::make('period')->label('Month')->options(fn () => static::periods())->default(now()->format('Y-m'))->selectablePlaceholder(false),
                SelectFilter::make('app')->label('App')->options(Tenant::APP_LABELS),
            ])
            ->groups([Group::make('tenant.name')->label('Tenant')->collapsible()])
            ->defaultGroup('tenant.name')
            ->defaultSort(fn ($query) => $query->orderBy('app')->orderByRaw('CASE metric ' . collect(array_keys(AssortmentUsage::METRICS))
                ->map(fn ($m, $i) => "WHEN '{$m}' THEN {$i}")->implode(' ') . ' ELSE 99 END')->orderBy('metric'))
            ->paginated([50, 100, 'all'])
            ->emptyStateHeading('No usage for this month')
            ->emptyStateDescription('Usage is rebuilt from each app\'s records every night. Rebuild it now from the button above.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('rebuild')->label('Rebuild from records')->icon('heroicon-o-arrow-path')->color('gray')
                ->requiresConfirmation()
                ->modalDescription(fn () => 'Recounts ' . \Carbon\Carbon::createFromFormat('Y-m', $this->period())->format('F Y') . ' for every tenant with Assortment Intelligence, from its runs, decisions, plans and results. The same records always give the same counts.')
                ->action(function () {
                    $n = 0;
                    foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
                        if ($tenant->hasApp(Tenant::APP_ASSORTMENT)) {
                            app(AssortmentUsage::class)->rebuild((int) $tenant->id, $this->period());
                            $n++;
                        }
                    }
                    Notification::make()->title("Usage rebuilt for {$n} tenant(s)")->success()->send();
                }),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [UsageStats::class];
    }

    public function getWidgetData(): array
    {
        return ['period' => $this->period()];
    }
}
