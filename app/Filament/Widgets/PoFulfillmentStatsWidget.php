<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Shared\BaseStatsWidget;
use App\Models\PurchaseOrder;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class PoFulfillmentStatsWidget extends BaseStatsWidget
{
    protected function getStats(): array
    {
        $tenantId = Filament::getTenant()?->id;

        $open = PurchaseOrder::where('tenant_id', $tenantId)
            ->whereNull('received_date')
            ->count();

        $overdue = PurchaseOrder::where('tenant_id', $tenantId)
            ->whereNull('received_date')
            ->whereNotNull('expected_date')
            ->where('expected_date', '<', today()->toDateString())
            ->count();

        $receivedThisMonth = PurchaseOrder::where('tenant_id', $tenantId)
            ->whereNotNull('received_date')
            ->where('received_date', '>=', Carbon::now()->startOfMonth()->toDateString())
            ->count();

        $total    = PurchaseOrder::where('tenant_id', $tenantId)->count();
        $received = PurchaseOrder::where('tenant_id', $tenantId)->whereNotNull('received_date')->count();
        $rate     = $total > 0 ? round(($received / $total) * 100) : 0;

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
