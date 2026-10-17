<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GatesPageByScreen;
use App\Filament\Widgets\Drivers\DriverContributors;
use App\Filament\Widgets\Drivers\DriverSummary;
use App\Filament\Widgets\Drivers\DriverWhat;
use App\Filament\Widgets\Drivers\DriverWhy;
use App\Models\SalesDriverAnalysis;
use App\Services\Drivers\DriverReports;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Root Cause — what moved sales, and why: a week (or any week the user picks)
 * against the same week last year or the 4 weeks before, for the business, a
 * store, a department or a category. Widgets: the headline and totals, what
 * moved (traffic, basket, mix, price), why (shoppers, stock, promotions,
 * events, range, unexplained), and the stores, departments and products that
 * moved it most.
 */
class PerformanceDrivers extends BaseDashboard
{
    use GatesPageByScreen;
    use HasFiltersForm;

    // v2.5 — sits behind the Briefings entry of the Root Cause menu (App\Support\Apps\MenuSets).
    use \App\Filament\Concerns\InMenuSet;
    use \App\Filament\Concerns\ShowsMenuSetTabs;

    public const MENU_SET = 'briefings';

    const SCREEN_KEY = 'performance_drivers';

    protected static string $routePath = 'performance-drivers';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-arrows-up-down';

    protected static \UnitEnum|string|null $navigationGroup = 'Root Cause';

    protected static ?string $navigationLabel = 'Performance drivers';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Performance drivers';

    public function getColumns(): int|array
    {
        return 1;
    }

    public function getWidgets(): array
    {
        return [DriverSummary::class, DriverWhat::class, DriverWhy::class, DriverContributors::class];
    }

    public function filtersForm(Schema $schema): Schema
    {
        $tenantId = (int) Filament::getTenant()?->id;

        return $schema->components([
            DatePicker::make('week')->label('Week (any day in it)')->native(false)->maxDate(now())
                ->helperText('Monday to Sunday. Empty: the last complete week.'),
            Select::make('compare')->label('Compared with')->options([
                'auto' => 'Same week last year (else the 4 weeks before)', 'prior4w' => 'The 4 weeks before',
            ])->default('auto')->selectablePlaceholder(false),
            Select::make('store')->label('Store')->placeholder('All stores')->searchable()
                ->options(fn () => DB::table('stores')->where('tenant_id', $tenantId)->orderBy('name')->pluck('name', 'id')->all()),
            Select::make('department')->label('Department')->placeholder('All departments')->searchable()
                ->options(fn () => self::departments($tenantId))
                ->afterStateUpdated(fn ($set) => $set('category', null)),
            Select::make('category')->label('Category')->placeholder('All categories')->searchable()
                ->options(fn ($get) => self::categories($tenantId, $get('department')))
                ->visible(fn ($get) => filled($get('department'))),
        ]);
    }

    /** @return array<string,string> */
    public static function departments(int $tenantId): array
    {
        $d = DB::table('category_daily')->where('tenant_id', $tenantId)->where('date', '>=', now()->subDays(400)->toDateString())
            ->distinct()->orderBy('department')->pluck('department')->all();

        return collect($d)->mapWithKeys(fn ($v) => [(string) $v => $v !== '' ? $v : 'Unassigned'])->all();
    }

    /** @return array<string,string> */
    public static function categories(int $tenantId, ?string $department): array
    {
        if ($department === null) {
            return [];
        }
        $c = DB::table('category_daily')->where('tenant_id', $tenantId)->where('department', $department)
            ->where('date', '>=', now()->subDays(400)->toDateString())->distinct()->orderBy('category')->pluck('category')->all();

        return collect($c)->mapWithKeys(fn ($v) => [(string) $v => $v !== '' ? $v : 'Unassigned'])->all();
    }

    /**
     * The breakdown the filters ask for (stored, or computed and stored).
     *
     * @param  array<string,mixed>|null  $filters
     */
    public static function analysis(?array $filters): ?SalesDriverAnalysis
    {
        $tenantId = (int) Filament::getTenant()?->id;
        if (! $tenantId) {
            return null;
        }
        $reports = app(DriverReports::class);
        $f = $filters ?? [];
        $prefer = ($f['compare'] ?? 'auto') === 'prior4w' ? 'prior4w' : null;
        $week = null;
        if (! empty($f['week'])) {
            try {
                $d = Carbon::parse((string) $f['week']);
                $week = [$d->copy()->startOfWeek(Carbon::MONDAY)->toDateString(), $d->copy()->startOfWeek(Carbon::MONDAY)->addDays(6)->toDateString()];
            } catch (\Throwable) {
                $week = null;
            }
        }
        if ($week === null) {
            $p = $reports->weeklyPeriods($tenantId);
            if ($p === null) {
                return null;
            }
            $week = [$p['from'], $p['to']];
        }
        $periods = $reports->comparisonFor($tenantId, $week[0], $week[1], $prefer);
        $scope = array_filter([
            'store_id'   => ! empty($f['store']) ? (int) $f['store'] : null,
            'department' => isset($f['department']) && $f['department'] !== null ? (string) $f['department'] : null,
            'category'   => isset($f['department'], $f['category']) && $f['category'] !== null ? (string) $f['category'] : null,
        ], fn ($v) => $v !== null);
        // A store must be the tenant's own.
        if (isset($scope['store_id']) && ! DB::table('stores')->where('tenant_id', $tenantId)->where('id', $scope['store_id'])->exists()) {
            return null;
        }

        try {
            return $reports->get($tenantId, $scope, $periods);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return $reports->get($tenantId, $scope, $periods);   // computed by a parallel request: read it
        }
    }
}
