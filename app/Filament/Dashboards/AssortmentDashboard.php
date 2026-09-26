<?php

namespace App\Filament\Dashboards;

use App\Models\AssortmentRun;
use App\Models\Tenant;

/**
 * The Assortment tab of the Dashboard. While the engine is in shadow mode
 * (validation gate not passed) it shows only whether the tenant's data is
 * ready for range decisions — never the decisions themselves.
 */
class AssortmentDashboard implements AppDashboard
{
    public function view(): string
    {
        return 'filament.dashboards.assortment';
    }

    public function data(?Tenant $tenant): array
    {
        $run = $tenant
            ? AssortmentRun::query()->where('tenant_id', $tenant->id)
                ->whereIn('status', [AssortmentRun::STATUS_SUCCESS, AssortmentRun::STATUS_SKIPPED])
                ->latest('id')->first()
            : null;
        $s = $run?->stats ?? [];

        $groups      = $s['peer_groups'] ?? [];
        $historyDays = (int) ($s['range']['history_days'] ?? 0);
        $minDelist   = (int) config('assortment.delist_min_history_days', 182);

        $checks = $run && $run->status === AssortmentRun::STATUS_SUCCESS ? [
            [
                'label' => 'Products carried per store',
                'value' => number_format(($s['range']['stores'] ?? 0) > 0 ? (int) round(($s['range']['carried'] ?? 0) / $s['range']['stores']) : 0),
                'foot'  => 'Average across ' . number_format((int) ($s['range']['stores'] ?? 0)) . ' stores, worked out from sales and stock history',
                'color' => 'info',
            ],
            [
                'label' => 'History',
                'value' => intdiv($historyDays, 7) . ' weeks',
                'foot'  => $historyDays >= $minDelist ? 'Enough for add and delist decisions' : 'Delists wait for ' . intdiv($minDelist, 7) . ' weeks',
                'color' => $historyDays >= $minDelist ? 'success' : 'warning',
            ],
            [
                'label' => 'Peer groups',
                'value' => (string) count($groups),
                'foot'  => ($s['stores_unjudged'] ?? 0) > 0
                    ? ($s['stores_unjudged'] . ' stores have too few similar stores to compare')
                    : 'Every store has similar stores to compare with',
                'color' => ($s['stores_unjudged'] ?? 0) > 0 ? 'warning' : 'success',
            ],
            [
                'label' => 'Stock history',
                'value' => number_format((int) ($s['stock_history']['days'] ?? 0)) . ' days',
                'foot'  => 'Used to tell slow sellers from products that keep running out',
                'color' => ((int) ($s['stock_history']['days'] ?? 0)) >= 28 ? 'success' : 'warning',
            ],
        ] : [];

        return [
            'run'    => $run,
            'asOf'   => $s['as_of'] ?? null,
            'reason' => $run && $run->status === AssortmentRun::STATUS_SKIPPED ? ($s['reason'] ?? null) : null,
            'checks' => $checks,
        ];
    }
}
