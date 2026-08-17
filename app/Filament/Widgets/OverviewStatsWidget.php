<?php

namespace App\Filament\Widgets;

use App\Models\Import;
use App\Models\InventoryLevel;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesTransaction;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OverviewStatsWidget extends BaseWidget
{
    protected string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $tenantId = Filament::getTenant()?->id;

        $products = Product::where('tenant_id', $tenantId)->count();

        $salesRows = SalesTransaction::where('tenant_id', $tenantId)->count();

        $inventoryRecords = InventoryLevel::where('tenant_id', $tenantId)->count();

        $lowStockCount = InventoryLevel::where('tenant_id', $tenantId)
            ->whereNotNull('reorder_point')
            ->whereColumn('on_hand_qty', '<=', 'reorder_point')
            ->count();

        $purchaseOrders = PurchaseOrder::where('tenant_id', $tenantId)->count();

        $pendingImports = Import::where('tenant_id', $tenantId)
            ->whereIn('status', [Import::STATUS_UPLOADED, Import::STATUS_MAPPING_REVIEW, Import::STATUS_IMPORTING])
            ->count();

        return [
            Stat::make('Products', number_format($products))
                ->description('Total SKUs loaded')
                ->descriptionIcon('heroicon-m-cube')
                ->color('primary'),

            Stat::make('Sales Records', number_format($salesRows))
                ->description('Transaction rows')
                ->descriptionIcon('heroicon-m-shopping-cart')
                ->color('success'),

            Stat::make('Inventory Records', number_format($inventoryRecords))
                ->description($lowStockCount > 0 ? "{$lowStockCount} below reorder point" : 'All levels healthy')
                ->descriptionIcon($lowStockCount > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($lowStockCount > 0 ? 'danger' : 'success'),

            Stat::make('Purchase Orders', number_format($purchaseOrders))
                ->description('Total PO lines')
                ->descriptionIcon('heroicon-m-truck')
                ->color('warning'),

            Stat::make('Pending Imports', number_format($pendingImports))
                ->description('Awaiting processing')
                ->descriptionIcon('heroicon-m-arrow-up-tray')
                ->color($pendingImports > 0 ? 'warning' : 'gray'),
        ];
    }
}
