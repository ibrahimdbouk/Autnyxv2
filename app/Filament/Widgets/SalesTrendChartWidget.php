<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Shared\BaseChartWidget;
use App\Models\SalesTransaction;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SalesTrendChartWidget extends BaseChartWidget
{
    protected ?string $heading = 'Sales Trend';

    protected int | string | array $columnSpan = 'full';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $tenantId = Filament::getTenant()?->id;

        $days = match ($this->filter) {
            '7d'  => 7,
            '90d' => 90,
            default => 30,
        };

        $from = Carbon::now()->subDays($days)->toDateString();

        // WP6.4: from the daily aggregate, not every receipt line.
        $rows = DB::table('sales_daily')->where('tenant_id', $tenantId)
            ->where('date', '>=', $from)
            ->select(
                DB::raw("TO_CHAR(date, 'YYYY-MM-DD') as day"),
                DB::raw('SUM(revenue) as total'),
                DB::raw('SUM(GREATEST(units_sold, 0)) as units'),
            )
            ->groupBy('date')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $labels  = [];
        $revenue = [];
        $units   = [];

        for ($i = $days; $i >= 0; $i--) {
            $day       = Carbon::now()->subDays($i)->toDateString();
            $labels[]  = Carbon::parse($day)->format('M d');
            $revenue[] = round((float) ($rows[$day]->total ?? 0), 2);
            $units[]   = round((float) ($rows[$day]->units ?? 0), 2);
        }

        return [
            'datasets' => [
                [
                    'label'           => 'Revenue ($)',
                    'data'            => $revenue,
                    'borderColor'     => self::AX_SERIES[0],
                    'backgroundColor' => self::axFill(self::AX_SERIES[0], 0.10),
                    'fill'            => true,
                    'tension'         => 0.4,
                    'pointRadius'     => 3,
                ],
                [
                    'label'           => 'Units Sold',
                    'data'            => $units,
                    'borderColor'     => self::AX_SERIES[2],
                    'backgroundColor' => self::axFill(self::AX_SERIES[2], 0.08),
                    'fill'            => false,
                    'tension'         => 0.4,
                    'pointRadius'     => 3,
                    'borderDash'      => [4, 4],
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => ['display' => true, 'position' => 'top'],
                'tooltip' => ['mode' => 'index', 'intersect' => false],
            ],
            'scales' => [
                'y' => ['beginAtZero' => true, 'grid' => ['color' => self::AX_GRID]],
                'x' => ['grid' => ['display' => false]],
            ],
            'interaction' => ['mode' => 'index'],
        ];
    }
}
