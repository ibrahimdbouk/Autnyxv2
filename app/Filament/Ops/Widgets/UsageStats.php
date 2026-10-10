<?php

namespace App\Filament\Ops\Widgets;

use App\Models\UsageMeter;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\Reactive;

/**
 * v2.1 — the month's usage across tenants, on top of the Ops usage table.
 * Follows the month picked in the table's filter.
 */
class UsageStats extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    #[Reactive]
    public ?string $period = null;

    protected ?string $heading = 'This month across tenants';

    protected function getStats(): array
    {
        $period = $this->period ?: now()->format('Y-m');
        $this->heading = 'Usage in ' . \Carbon\Carbon::createFromFormat('Y-m', $period)->format('F Y') . ' across tenants';
        $rows = UsageMeter::where('period', $period)->get(['tenant_id', 'app', 'metric', 'count']);
        $sum = fn (string $app, string $metric) => (int) $rows->where('app', $app)->where('metric', $metric)->sum('count');

        return [
            Stat::make('Tenants with usage', number_format($rows->where('count', '>', 0)->pluck('tenant_id')->unique()->count()))
                ->description('Any app, any metric'),
            Stat::make('Assortment runs', number_format($sum('assortment', 'runs')))
                ->description(number_format((int) $rows->where('app', 'assortment')->where('metric', 'stores')->sum('count')) . ' stores judged'),
            Stat::make('Decisions accepted', number_format($sum('assortment', 'decisions_accepted')))
                ->description(number_format($sum('assortment', 'decisions_proposed')) . ' proposed'),
            Stat::make('Plans accepted', number_format($sum('assortment', 'plans_accepted')))
                ->description(number_format($sum('assortment', 'studio_plans')) . ' made in the Decision Studio'),
            Stat::make('Results measured', number_format($sum('assortment', 'results_measured')))
                ->description('Decisions and plans, against similar stores'),
        ];
    }
}
