<?php

namespace App\Filament\Widgets;

use App\Models\Anomaly;
use App\Models\Import;
use App\Models\InventoryLevel;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesTransaction;
use App\Models\Store;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OverviewStatsWidget extends BaseWidget
{
    protected ?string $pollingInterval = '60s';

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

        $stores = Store::where('tenant_id', $tenantId)->count();

        $purchaseOrders = PurchaseOrder::where('tenant_id', $tenantId)->count();

        $pendingImports = Import::where('tenant_id', $tenantId)
            ->whereIn('status', [Import::STATUS_UPLOADED, Import::STATUS_MAPPING_REVIEW, Import::STATUS_IMPORTING])
            ->count();

        $highAnomalies   = Anomaly::where('tenant_id', $tenantId)->active()->where('severity', 'high')->count();
        $mediumAnomalies = Anomaly::where('tenant_id', $tenantId)->active()->where('severity', 'medium')->count();
        $totalAnomalies  = Anomaly::where('tenant_id', $tenantId)->active()->count();

        // B1: aggregate estimated value at risk across open anomalies, in the tenant's currency.
        $valueAtRisk = $tenantId ? Anomaly::estimatedValueAtRiskForTenant($tenantId) : 0.0;
        $currency    = Filament::getTenant()?->currencyCode();

        $anomalyDesc = match (true) {
            $highAnomalies > 0   => "{$highAnomalies} high severity",
            $mediumAnomalies > 0 => "{$mediumAnomalies} medium severity",
            $totalAnomalies > 0  => "{$totalAnomalies} low severity",
            default              => 'No open anomalies',
        };

        // Deep-link each stat to the resource that lists its underlying rows.
        $anomaliesUrl = \App\Filament\Resources\AnomalyResource::getUrl('index');
        $productsUrl  = \App\Filament\Resources\ProductResource::getUrl('index');
        $salesUrl     = \App\Filament\Resources\SalesTransactionResource::getUrl('index');
        $inventoryUrl = \App\Filament\Resources\InventoryLevelResource::getUrl('index');
        $storesUrl    = \App\Filament\Resources\StoreResource::getUrl('index');
        $poUrl        = \App\Filament\Resources\PurchaseOrderResource::getUrl('index');
        $importsUrl   = \App\Filament\Resources\ImportResource::getUrl('index');

        return [
            Stat::make('Open Anomalies', number_format($totalAnomalies))
                ->description($anomalyDesc)
                ->descriptionIcon($totalAnomalies > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($highAnomalies > 0 ? 'danger' : ($mediumAnomalies > 0 ? 'warning' : ($totalAnomalies > 0 ? 'info' : 'success')))
                ->url($anomaliesUrl)
                ->extraAttributes(['class' => 'cursor-pointer']),

            Stat::make('Est. Value at Risk', \App\Support\Money::compact($valueAtRisk, $currency))
                ->description('Across open anomalies (estimate)')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color($valueAtRisk > 0 ? 'warning' : 'success')
                ->url($anomaliesUrl)
                ->extraAttributes(['class' => 'cursor-pointer']),

            Stat::make('Products', number_format($products))
                ->description('Total SKUs loaded')
                ->descriptionIcon('heroicon-m-cube')
                ->color('primary')
                ->url($productsUrl)
                ->extraAttributes(['class' => 'cursor-pointer']),

            Stat::make('Sales Records', number_format($salesRows))
                ->description('Transaction rows')
                ->descriptionIcon('heroicon-m-shopping-cart')
                ->color('success')
                ->url($salesUrl)
                ->extraAttributes(['class' => 'cursor-pointer']),

            Stat::make('Inventory Records', number_format($inventoryRecords))
                ->description($lowStockCount > 0 ? "{$lowStockCount} below reorder point" : 'All levels healthy')
                ->descriptionIcon($lowStockCount > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($lowStockCount > 0 ? 'danger' : 'success')
                ->url($inventoryUrl)
                ->extraAttributes(['class' => 'cursor-pointer']),

            Stat::make('Stores', number_format($stores))
                ->description('Locations from imports')
                ->descriptionIcon('heroicon-m-building-storefront')
                ->color('info')
                ->url($storesUrl)
                ->extraAttributes(['class' => 'cursor-pointer']),

            Stat::make('Purchase Orders', number_format($purchaseOrders))
                ->description('Total PO lines')
                ->descriptionIcon('heroicon-m-truck')
                ->color('warning')
                ->url($poUrl)
                ->extraAttributes(['class' => 'cursor-pointer']),

            Stat::make('Pending Imports', number_format($pendingImports))
                ->description('Awaiting processing')
                ->descriptionIcon('heroicon-m-arrow-up-tray')
                ->color($pendingImports > 0 ? 'warning' : 'gray')
                ->url($importsUrl)
                ->extraAttributes(['class' => 'cursor-pointer']),
        ];
    }
}
