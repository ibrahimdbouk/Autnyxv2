<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Shared\BaseChartWidget;
use App\Models\SalesTransaction;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TopSkusChartWidget extends BaseChartWidget
{
    protected ?string $heading = 'Top 10 SKUs by Revenue';

    protected int | string | array $columnSpan = 1;

    protected function getType(): string
    {
        return 'bar';
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

        $rows = SalesTransaction::where('tenant_id', $tenantId)
            ->where('date', '>=', $from)
            ->whereNotNull('sku')
            ->where('quantity', '>', 0)
            ->select('sku', DB::raw('SUM(total_amount) as total'))
            ->groupBy('sku')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        $colors = [
            'rgba(124,58,237,0.75)',
            'rgba(99,102,241,0.75)',
            'rgba(59,130,246,0.75)',
            'rgba(16,185,129,0.75)',
            'rgba(20,184,166,0.75)',
            'rgba(245,158,11,0.75)',
            'rgba(239,68,68,0.75)',
            'rgba(236,72,153,0.75)',
            'rgba(168,85,247,0.75)',
            'rgba(14,165,233,0.75)',
        ];

        return [
            'datasets' => [
                [
                    'label'           => 'Revenue ($)',
                    'data'            => $rows->pluck('total')->map(fn ($v) => round((float) $v, 2))->values()->toArray(),
                    'backgroundColor' => array_slice($colors, 0, $rows->count()),
                    'borderWidth'     => 0,
                    'borderRadius'    => 4,
                ],
            ],
            'labels' => $rows->pluck('sku')->values()->toArray(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'plugins'   => ['legend' => ['display' => false]],
            'scales'    => [
                'x' => ['beginAtZero' => true, 'grid' => ['color' => 'rgba(0,0,0,0.05)']],
                'y' => ['grid' => ['display' => false]],
            ],
        ];
    }
}
