<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Shared\BaseStatsWidget;
use App\Models\PurchaseOrder;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class PoFulfillmentStatsWidget extends BaseStatsWidget
{
    use \App\Filament\Widgets\Shared\CachesPerTenant;

    protected function getStats(): array
    {
        $tenantId = Filament::getTenant()?->id;

        // WP6.4: one pass over the tenant's POs (not five), cached for 2 minutes.
        $f = $this->cachedForTenant('po', fn () => (array) PurchaseOrder::where('tenant_id', $tenantId)->selectRaw(
            'COUNT(*) FILTER (WHERE received_date IS NULL) AS open,
             COUNT(*) FILTER (WHERE received_date IS NULL AND expected_date < ?) AS overdue,
             COUNT(*) FILTER (WHERE received_date >= ?) AS received_this_month,
             COUNT(*) AS total,
             COUNT(received_date) AS received',
            [\App\Support\Tenancy\TenantClock::localDate($tenantId), \App\Support\Tenancy\TenantClock::localMonthStart($tenantId)]
        )->toBase()->first());

        $open              = (int) ($f['open'] ?? 0);
        $overdue           = (int) ($f['overdue'] ?? 0);
        $receivedThisMonth = (int) ($f['received_this_month'] ?? 0);
        $total             = (int) ($f['total'] ?? 0);
        $received          = (int) ($f['received'] ?? 0);
        $rate              = $total > 0 ? round(($received / $total) * 100) : 0;

        return [
            Stat::make('Open Purchase Orders', number_format($open))
                ->description($overdue > 0 ? "{$overdue} overdue" : 'All on schedule')
                ->descriptionIcon($overdue > 0 ? 'heroicon-m-clock' : 'heroicon-m-check-circle')
                ->color($overdue > 0 ? 'danger' : 'success'),

            Stat::make('Overdue POs', number_format($overdue))
                ->description('Past expected delivery date')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($overdue > 0 ? 'danger' : 'success'),

            Stat::make('Received This Month', number_format($receivedThisMonth))
                ->description("Fulfillment rate: {$rate}% all-time")
                ->descriptionIcon('heroicon-m-truck')
                ->color('primary'),
        ];
    }
}
