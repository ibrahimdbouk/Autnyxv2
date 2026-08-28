<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Shared\BaseChartWidget;
use App\Models\Anomaly;
use Filament\Facades\Filament;

class StoreComparisonWidget extends BaseChartWidget
{
    protected ?string $heading = 'Anomalies by Store (Last 30 Days)';

    protected static ?int $sort = 16;

    protected int | string | array $columnSpan = 'full';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getFilters(): ?array
    {
        return null; // Fixed 30-day window
    }

    protected function getData(): array
    {
        $tenantId = Filament::getTenant()?->id;

        $rows = Anomaly::where('tenant_id', $tenantId)
            ->where('detected_at', '>=', now()->subDays(30))
            ->whereNotNull('store_id')
            ->selectRaw('store_id, COUNT(*) as total')
            ->groupBy('store_id')
            ->orderByDesc('total')
            ->get();

        $labels = $rows->pluck('store_id')->map(fn ($id) => 'Store ' . $id)->toArray();
        $counts = $rows->pluck('total')->map(fn ($v) => (int) $v)->toArray();

        return [
            'datasets' => [
                [
                    'label'           => 'Anomalies',
                    'data'            => $counts,
                    'backgroundColor' => self::axFill(self::AX_ACCENT, 0.85),
                    'borderColor'     => self::AX_ACCENT,
                    'borderWidth'     => 1,
                    'borderRadius'    => 4,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => ['display' => false],
                'tooltip' => ['mode' => 'index', 'intersect' => false],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'ticks'       => ['stepSize' => 1],
                    'grid'        => ['color' => self::AX_GRID],
                ],
                'x' => ['grid' => ['display' => false]],
            ],
        ];
    }
}
