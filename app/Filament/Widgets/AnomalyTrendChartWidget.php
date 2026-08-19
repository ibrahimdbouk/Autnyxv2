<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Shared\BaseChartWidget;
use App\Models\Anomaly;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;

class AnomalyTrendChartWidget extends BaseChartWidget
{
    protected ?string $heading = 'Anomaly Trend (30 Days)';

    protected static ?int $sort = 15;

    protected int | string | array $columnSpan = 'full';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getFilters(): ?array
    {
        return null; // Fixed 30-day window
    }

    protected function getData(): array
    {
        $tenantId = Filament::getTenant()?->id;

        $anomalies = Anomaly::where('tenant_id', $tenantId)
            ->where('detected_at', '>=', now()->subDays(30))
            ->get();

        $labels = [];
        $high   = [];
        $medium = [];
        $low    = [];

        for ($i = 29; $i >= 0; $i--) {
            $date     = Carbon::now()->subDays($i)->toDateString();
            $labels[] = Carbon::parse($date)->format('M d');

            $dayAnomalies = $anomalies->filter(
                fn (Anomaly $a) => $a->detected_at?->toDateString() === $date
            );

            $high[]   = $dayAnomalies->where('severity', 'high')->count();
            $medium[] = $dayAnomalies->where('severity', 'medium')->count();
            $low[]    = $dayAnomalies->where('severity', 'low')->count();
        }

        return [
            'datasets' => [
                [
                    'label'           => 'High',
                    'data'            => $high,
                    'borderColor'     => '#ef4444',
                    'backgroundColor' => 'rgba(239, 68, 68, 0.08)',
                    'fill'            => false,
                    'tension'         => 0.4,
                    'pointRadius'     => 3,
                ],
                [
                    'label'           => 'Medium',
                    'data'            => $medium,
                    'borderColor'     => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.08)',
                    'fill'            => false,
                    'tension'         => 0.4,
                    'pointRadius'     => 3,
                ],
                [
                    'label'           => 'Low',
                    'data'            => $low,
                    'borderColor'     => '#3b82f6',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.08)',
                    'fill'            => false,
                    'tension'         => 0.4,
                    'pointRadius'     => 3,
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
                'y' => [
                    'beginAtZero' => true,
                    'ticks'       => ['stepSize' => 1],
                    'grid'        => ['color' => 'rgba(0,0,0,0.05)'],
                ],
                'x' => ['grid' => ['display' => false]],
            ],
            'interaction' => ['mode' => 'index'],
        ];
    }
}
