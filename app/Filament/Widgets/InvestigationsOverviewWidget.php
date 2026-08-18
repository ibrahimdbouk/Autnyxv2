<?php

namespace App\Filament\Widgets;

use App\Models\Investigation;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

class InvestigationsOverviewWidget extends Widget
{
    protected static string $view = 'filament.widgets.investigations-overview-widget';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 1;

    public function getViewData(): array
    {
        $tenantId = Filament::getTenant()?->id;

        if (! $tenantId) {
            return [
                'open'        => 0,
                'inProgress'  => 0,
                'critical'    => 0,
                'high'        => 0,
                'recentlyResolved' => 0,
                'avgOpenHours' => null,
            ];
        }

        $base = Investigation::where('tenant_id', $tenantId);

        $open       = (clone $base)->where('status', Investigation::STATUS_OPEN)->count();
        $inProgress = (clone $base)->where('status', Investigation::STATUS_IN_PROGRESS)->count();

        $critical = (clone $base)
            ->whereIn('status', [Investigation::STATUS_OPEN, Investigation::STATUS_IN_PROGRESS])
            ->where('priority', Investigation::PRIORITY_CRITICAL)
            ->count();

        $high = (clone $base)
            ->whereIn('status', [Investigation::STATUS_OPEN, Investigation::STATUS_IN_PROGRESS])
            ->where('priority', Investigation::PRIORITY_HIGH)
            ->count();

        $recentlyResolved = (clone $base)
            ->where('status', Investigation::STATUS_RESOLVED)
            ->where('resolved_at', '>=', now()->subDays(7))
            ->count();

        // Average hours open for in-flight investigations
        $avgOpenHours = (clone $base)
            ->whereIn('status', [Investigation::STATUS_OPEN, Investigation::STATUS_IN_PROGRESS])
            ->whereNotNull('opened_at')
            ->selectRaw("AVG(EXTRACT(EPOCH FROM (NOW() - opened_at)) / 3600) as avg_hours")
            ->value('avg_hours');

        return [
            'open'             => $open,
            'inProgress'       => $inProgress,
            'critical'         => $critical,
            'high'             => $high,
            'recentlyResolved' => $recentlyResolved,
            'avgOpenHours'     => $avgOpenHours ? round($avgOpenHours, 1) : null,
        ];
    }
}
