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

        // WP6.2: current positions per store (lots summed) — history rows would
        // count every past snapshot as stock.
        $rows = DB::table('inventory_current as c')
            ->leftJoin('stores as s', 's.id', '=', 'c.store_id')
            ->where('c.tenant_id', $tenantId)
            ->select(
                'c.store_id',
                DB::raw('MAX(s.name) as store_name'),
                DB::raw("SUM(CASE WHEN c.on_hand_qty <= 0 THEN 1 ELSE 0 END) as stockout"),
                DB::raw("SUM(CASE WHEN c.on_hand_qty > 0 AND c.reorder_point > 0 AND c.on_hand_qty <= c.reorder_point THEN 1 ELSE 0 END) as at_risk"),
                DB::raw("SUM(CASE WHEN c.on_hand_qty > 0 AND (c.reorder_point IS NULL OR c.reorder_point <= 0 OR c.on_hand_qty > c.reorder_point) THEN 1 ELSE 0 END) as healthy"),
                DB::raw('COUNT(*) as total')
            )
            ->groupBy('c.store_id')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        $labels = $rows->map(fn ($r) => $r->store_name ?: "Store {$r->store_id}")->toArray();

        if ($rows->isEmpty()) {
            return [
                'datasets' => [
                    ['label' => 'Healthy',  'data' => [], 'backgroundColor' => self::axFill(self::AX_SUCCESS, 0.78)],
                    ['label' => 'At Risk',  'data' => [], 'backgroundColor' => self::axFill(self::AX_WARNING, 0.78)],
                    ['label' => 'Stockout', 'data' => [], 'backgroundColor' => self::axFill(self::AX_DANGER, 0.78)],
                ],
                'labels' => [],
            ];
        }

        return [
            'datasets' => [
                [
                    'label'           => 'Healthy',
                    'data'            => $rows->pluck('healthy')->map(fn ($v) => (int) $v)->values()->toArray(),
                    'backgroundColor' => self::axFill(self::AX_SUCCESS, 0.78),
                    'borderRadius'    => 3,
                ],
                [
                    'label'           => 'At Risk',
                    'data'            => $rows->pluck('at_risk')->map(fn ($v) => (int) $v)->values()->toArray(),
                    'backgroundColor' => self::axFill(self::AX_WARNING, 0.78),
                    'borderRadius'    => 3,
                ],
                [
                    'label'           => 'Stockout',
                    'data'            => $rows->pluck('stockout')->map(fn ($v) => (int) $v)->values()->toArray(),
                    'backgroundColor' => self::axFill(self::AX_DANGER, 0.78),
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
                'y' => ['stacked' => true, 'beginAtZero' => true, 'grid' => ['color' => self::AX_GRID]],
            ],
        ];
    }
}
