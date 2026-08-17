<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Shared\BaseChartWidget;
use App\Models\InventoryLevel;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;

class InventoryHealthChartWidget extends BaseChartWidget
{
    protected ?string $heading = 'Inventory Health by Location';

    protected int | string | array $columnSpan = 1;

    protected function getFilters(): ?array
    {
        return null;
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $tenantId = Filament::getTenant()?->id;

        // Try to group by location string first
        $hasLocations = InventoryLevel::where('tenant_id', $tenantId)
            ->whereNotNull('location')
            ->exists();

        if ($hasLocations) {
            $rows = InventoryLevel::where('tenant_id', $tenantId)
                ->whereNotNull('location')
                ->select(
                    'location',
                    DB::raw("SUM(CASE WHEN on_hand_qty <= 0 THEN 1 ELSE 0 END) as stockout"),
                    DB::raw("SUM(CASE WHEN on_hand_qty > 0 AND reorder_point IS NOT NULL AND on_hand_qty <= reorder_point THEN 1 ELSE 0 END) as at_risk"),
                    DB::raw("SUM(CASE WHEN on_hand_qty > 0 AND (reorder_point IS NULL OR on_hand_qty > reorder_point) THEN 1 ELSE 0 END) as healthy"),
                    DB::raw('COUNT(*) as total')
                )
                ->groupBy('location')
                ->orderByDesc('total')
                ->limit(10)
                ->get();

            $labels = $rows->pluck('location')->toArray();
        } else {
            // Fall back to store_id grouping
            $rows = InventoryLevel::where('tenant_id', $tenantId)
                ->whereNotNull('store_id')
                ->select(
                    'store_id',
                    DB::raw("SUM(CASE WHEN on_hand_qty <= 0 THEN 1 ELSE 0 END) as stockout"),
                    DB::raw("SUM(CASE WHEN on_hand_qty > 0 AND reorder_point IS NOT NULL AND on_hand_qty <= reorder_point THEN 1 ELSE 0 END) as at_risk"),
                    DB::raw("SUM(CASE WHEN on_hand_qty > 0 AND (reorder_point IS NULL OR on_hand_qty > reorder_point) THEN 1 ELSE 0 END) as healthy"),
                    DB::raw('COUNT(*) as total')
                )
                ->groupBy('store_id')
                ->orderByDesc('total')
                ->limit(10)
                ->get();

            $labels = $rows->pluck('store_id')->map(fn ($id) => "Store {$id}")->toArray();
        }

        if ($rows->isEmpty()) {
            return [
                'datasets' => [
                    ['label' => 'Healthy',  'data' => [], 'backgroundColor' => 'rgba(16,185,129,0.75)'],
                    ['label' => 'At Risk',  'data' => [], 'backgroundColor' => 'rgba(245,158,11,0.75)'],
                    ['label' => 'Stockout', 'data' => [], 'backgroundColor' => 'rgba(239,68,68,0.75)'],
                ],
                'labels' => [],
            ];
        }

        return [
            'datasets' => [
                [
                    'label'           => 'Healthy',
                    'data'            => $rows->pluck('healthy')->map(fn ($v) => (int) $v)->values()->toArray(),
                    'backgroundColor' => 'rgba(16,185,129,0.75)',
                    'borderRadius'    => 3,
                ],
                [
                    'label'           => 'At Risk',
                    'data'            => $rows->pluck('at_risk')->map(fn ($v) => (int) $v)->values()->toArray(),
                    'backgroundColor' => 'rgba(245,158,11,0.75)',
                    'borderRadius'    => 3,
                ],
                [
                    'label'           => 'Stockout',
                    'data'            => $rows->pluck('stockout')->map(fn ($v) => (int) $v)->values()->toArray(),
                    'backgroundColor' => 'rgba(239,68,68,0.75)',
                    'borderRadius'    => 3,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => ['display' => true, 'position' => 'bottom'],
            ],
            'scales' => [
                'x' => ['stacked' => true, 'grid' => ['display' => false]],
                'y' => ['stacked' => true, 'beginAtZero' => true, 'grid' => ['color' => 'rgba(0,0,0,0.05)']],
            ],
        ];
    }
}
