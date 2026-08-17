<?php

namespace App\Services\Anomaly;

use App\Models\Anomaly;
use App\Models\AnomalySetting;
use App\Models\Import;
use App\Models\InventoryLevel;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesTransaction;
use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AnomalyDetectionService
{
    /**
     * Run all enabled rules for a tenant and store results in the anomalies table.
     */
    public function runForTenant(int $tenantId): void
    {
        AnomalySetting::seedForTenant($tenantId);

        $settings = AnomalySetting::where('tenant_id', $tenantId)
            ->get()
            ->keyBy('rule_type');

        $t = fn (string $rule) => $settings->get($rule)?->getEffectiveThresholds() ?? [];

        $rules = [
            // Demand & Sales
            'sales_spike'                => fn () => $this->detectSalesSpike($tenantId, $t('sales_spike')),
            'sales_drop'                 => fn () => $this->detectSalesDrop($tenantId, $t('sales_drop')),
            'demand_seasonality_breach'  => fn () => $this->detectDemandSeasonalityBreach($tenantId, $t('demand_seasonality_breach')),
            'cannibalization_signal'     => fn () => $this->detectCannibalizationSignal($tenantId, $t('cannibalization_signal')),
            'return_rate_spike'          => fn () => $this->detectReturnRateSpike($tenantId, $t('return_rate_spike')),
            'channel_mix_shift'          => fn () => $this->detectChannelMixShift($tenantId, $t('channel_mix_shift')),

            // Inventory & Supply
            'stockout_risk'              => fn () => $this->detectStockoutRisk($tenantId),
            'safety_stock_breach'        => fn () => $this->detectSafetyStockBreach($tenantId),
            'dead_stock'                 => fn () => $this->detectDeadStock($tenantId, $t('dead_stock')),
            'phantom_inventory'          => fn () => $this->detectPhantomInventory($tenantId),
            'multi_location_imbalance'   => fn () => $this->detectMultiLocationImbalance($tenantId),
            'reorder_point_staleness'    => fn () => $this->detectReorderPointStaleness($tenantId, $t('reorder_point_staleness')),
            'inventory_shrinkage'        => fn () => $this->detectInventoryShrinkage($tenantId, $t('inventory_shrinkage')),

            // Purchase Orders
            'po_overdue'                 => fn () => $this->detectPoOverdue($tenantId),
            'receiving_discrepancy'      => fn () => $this->detectReceivingDiscrepancy($tenantId, $t('receiving_discrepancy')),
            'supplier_lead_time_drift'   => fn () => $this->detectSupplierLeadTimeDrift($tenantId, $t('supplier_lead_time_drift')),
            'cost_spike'                 => fn () => $this->detectCostSpike($tenantId, $t('cost_spike')),

            // Financial
            'price_anomaly'              => fn () => $this->detectPriceAnomaly($tenantId, $t('price_anomaly')),
            'margin_erosion'             => fn () => $this->detectMarginErosion($tenantId),
            'discount_signal'            => fn () => $this->detectDiscountSignal($tenantId),
            'revenue_concentration_risk' => fn () => $this->detectRevenueConcentrationRisk($tenantId, $t('revenue_concentration_risk')),
            'slow_moving_capital'        => fn () => $this->detectSlowMovingCapital($tenantId, $t('slow_moving_capital')),

            // Store Performance
            'store_outlier'              => fn () => $this->detectStoreOutlier($tenantId, $t('store_outlier')),

            // Operational / Data Quality
            'import_frequency_gap'       => fn () => $this->detectImportFrequencyGap($tenantId, $t('import_frequency_gap')),
            'duplicate_transaction_ids'  => fn () => $this->detectDuplicateTransactionIds($tenantId),
            'sku_master_drift'           => fn () => $this->detectSkuMasterDrift($tenantId),
            'location_proliferation'     => fn () => $this->detectLocationProliferation($tenantId, $t('location_proliferation')),
        ];

        foreach ($rules as $ruleType => $detector) {
            $setting = $settings->get($ruleType);
            if (!$setting || !$setting->enabled) {
                continue;
            }

            Anomaly::where('tenant_id', $tenantId)
                ->where('rule_type', $ruleType)
                ->whereNull('dismissed_at')
                ->delete();

            try {
                $detector();
            } catch (\Throwable $e) {
                Log::error("Anomaly detection failed [{$ruleType}]", [
                    'tenant_id' => $tenantId,
                    'error'     => $e->getMessage(),
                    'trace'     => $e->getTraceAsString(),
                ]);
            }
        }
    }

    // =========================================================================
    // DEMAND & SALES
    // =========================================================================

    private function detectSalesSpike(int $tenantId, array $thresholds): void
    {
        $pct  = (float)($thresholds['pct'] ?? 50);
        $days = (int)($thresholds['days'] ?? 7);

        [$recent, $historical, $periods] = $this->salesComparison($tenantId, $days);

        foreach ($recent as $sku => $recentQty) {
            $histQty = (float)($historical->get($sku) ?? 0);
            if ($histQty <= 0) continue;

            $avgQty    = $histQty / $periods;
            $changePct = (($recentQty - $avgQty) / $avgQty) * 100;

            if ($changePct >= $pct) {
                $this->flag($tenantId, 'sales_spike', 'low', $sku, null, null,
                    "SKU {$sku} sales spiked " . round($changePct) . "% above its {$days}-day average "
                    . "(recent: " . round($recentQty) . " units, avg: " . round($avgQty) . " units).",
                    ['recent_qty' => $recentQty, 'avg_qty' => round($avgQty, 2), 'change_pct' => round($changePct, 1), 'days' => $days]
                );
            }
        }
    }

    private function detectSalesDrop(int $tenantId, array $thresholds): void
    {
        $pct  = (float)($thresholds['pct'] ?? 30);
        $days = (int)($thresholds['days'] ?? 7);

        [$recent, $historical, $periods] = $this->salesComparison($tenantId, $days);

        foreach ($historical->keys() as $sku) {
            $histQty   = (float)$historical->get($sku);
            $recentQty = (float)($recent->get($sku) ?? 0);
            if ($histQty <= 0) continue;

            $avgQty    = $histQty / $periods;
            $changePct = (($avgQty - $recentQty) / $avgQty) * 100;

            if ($changePct >= $pct) {
                $this->flag($tenantId, 'sales_drop', 'medium', $sku, null, null,
                    "SKU {$sku} sales dropped " . round($changePct) . "% below its {$days}-day average "
                    . "(recent: " . round($recentQty) . " units, avg: " . round($avgQty) . " units).",
                    ['recent_qty' => $recentQty, 'avg_qty' => round($avgQty, 2), 'change_pct' => round($changePct, 1), 'days' => $days]
                );
            }
        }
    }

    private function detectDemandSeasonalityBreach(int $tenantId, array $thresholds): void
    {
        $pct = (float)($thresholds['pct'] ?? 40);

        $currentEnd   = Carbon::today()->format('Y-m-d');
        $currentStart = Carbon::today()->subDays(30)->format('Y-m-d');
        $priorEnd     = Carbon::today()->subYear()->format('Y-m-d');
        $priorStart   = Carbon::today()->subYear()->subDays(30)->format('Y-m-d');

        $current = SalesTransaction::where('tenant_id', $tenantId)
            ->whereBetween('date', [$currentStart, $currentEnd])
            ->selectRaw('sku, SUM(quantity) as qty')
            ->groupBy('sku')
            ->pluck('qty', 'sku')
            ->map(fn ($v) => (float) $v);

        $prior = SalesTransaction::where('tenant_id', $tenantId)
            ->whereBetween('date', [$priorStart, $priorEnd])
            ->selectRaw('sku, SUM(quantity) as qty')
            ->groupBy('sku')
            ->pluck('qty', 'sku')
            ->map(fn ($v) => (float) $v);

        if ($prior->isEmpty()) return;

        foreach ($current as $sku => $currentQty) {
            $priorQty = (float)($prior->get($sku) ?? 0);
            if ($priorQty <= 0) continue;

            $changePct = abs(($currentQty - $priorQty) / $priorQty) * 100;
            if ($changePct < $pct) continue;

            $direction = $currentQty > $priorQty ? 'above' : 'below';
            $product   = Product::where('tenant_id', $tenantId)->where('sku', $sku)->first();

            $this->flag($tenantId, 'demand_seasonality_breach', 'medium', $sku, null, $product?->id,
                "SKU {$sku} sold " . round($currentQty) . " units in the last 30 days — "
                . round($changePct) . "% {$direction} the same window last year (" . round($priorQty) . " units).",
                ['current_qty' => $currentQty, 'prior_year_qty' => $priorQty, 'change_pct' => round($changePct, 1), 'direction' => $direction]
            );
        }
    }

    private function detectCannibalizationSignal(int $tenantId, array $thresholds): void
    {
        $pct  = (float)($thresholds['pct'] ?? 30);
        $days = (int)($thresholds['days'] ?? 30);

        [$recent, $historical, $periods] = $this->salesComparison($tenantId, $days);

        if ($recent->isEmpty() || $historical->isEmpty()) return;

        $allSkus   = $recent->keys()->merge($historical->keys())->unique();
        $products  = Product::where('tenant_id', $tenantId)
            ->whereNotNull('category')
            ->whereIn('sku', $allSkus)
            ->get()
            ->groupBy('category');

        foreach ($products as $category => $categoryProducts) {
            if ($categoryProducts->count() < 2) continue;

            $rising  = [];
            $falling = [];

            foreach ($categoryProducts as $product) {
                $sku       = $product->sku;
                $recentQty = (float)($recent->get($sku) ?? 0);
                $histQty   = (float)($historical->get($sku) ?? 0);

                if ($histQty <= 0) continue;

                $avgQty    = $histQty / $periods;
                $changePct = (($recentQty - $avgQty) / $avgQty) * 100;

                if ($changePct >= $pct)  $rising[]  = ['sku' => $sku, 'change' => round($changePct, 1)];
                if ($changePct <= -$pct) $falling[] = ['sku' => $sku, 'change' => round($changePct, 1), 'product_id' => $product->id];
            }

            if (!empty($rising) && !empty($falling)) {
                $risingSkus = implode(', ', array_column($rising, 'sku'));
                foreach ($falling as $f) {
                    $this->flag($tenantId, 'cannibalization_signal', 'medium', $f['sku'], null, $f['product_id'],
                        "SKU {$f['sku']} (category: {$category}) fell {$f['change']}% while sibling SKU(s) {$risingSkus} rose — possible cannibalization.",
                        ['category' => $category, 'falling_sku' => $f['sku'], 'rising_skus' => $rising, 'change_pct' => $f['change']]
                    );
                }
            }
        }
    }

    private function detectReturnRateSpike(int $tenantId, array $thresholds): void
    {
        $pct   = (float)($thresholds['pct'] ?? 15);
        $days  = (int)($thresholds['days'] ?? 30);
        $since = Carbon::today()->subDays($days)->format('Y-m-d');

        $txsBySku = SalesTransaction::where('tenant_id', $tenantId)
            ->where('date', '>=', $since)
            ->get()
            ->groupBy('sku');

        foreach ($txsBySku as $sku => $skuTxs) {
            $sales   = (float) $skuTxs->where('quantity', '>', 0)->sum('quantity');
            $returns = (float) abs($skuTxs->where('quantity', '<', 0)->sum('quantity'));

            if ($sales <= 0 || $returns <= 0) continue;

            $returnRate = ($returns / ($sales + $returns)) * 100;

            if ($returnRate >= $pct) {
                $product = Product::where('tenant_id', $tenantId)->where('sku', $sku)->first();
                $this->flag($tenantId, 'return_rate_spike', 'medium', $sku, null, $product?->id,
                    "SKU {$sku} has a " . round($returnRate, 1) . "% return rate over the last {$days} days "
                    . "(" . round($returns) . " units returned vs " . round($sales) . " units sold).",
                    ['sales_qty' => $sales, 'returns_qty' => $returns, 'return_rate_pct' => round($returnRate, 1), 'days' => $days]
                );
            }
        }
    }

    private function detectChannelMixShift(int $tenantId, array $thresholds): void
    {
        $pct  = (float)($thresholds['pct'] ?? 25);
        $days = (int)($thresholds['days'] ?? 30);

        $recentStart = Carbon::today()->subDays($days)->format('Y-m-d');
        $priorStart  = Carbon::today()->subDays($days * 2)->format('Y-m-d');
        $priorEnd    = Carbon::today()->subDays($days)->format('Y-m-d');

        $recentByLoc = SalesTransaction::where('tenant_id', $tenantId)
            ->where('date', '>=', $recentStart)
            ->whereNotNull('location')
            ->selectRaw('location, SUM(quantity) as qty')
            ->groupBy('location')
            ->pluck('qty', 'location')
            ->map(fn ($v) => (float) $v);

        $priorByLoc = SalesTransaction::where('tenant_id', $tenantId)
            ->whereBetween('date', [$priorStart, $priorEnd])
            ->whereNotNull('location')
            ->selectRaw('location, SUM(quantity) as qty')
            ->groupBy('location')
            ->pluck('qty', 'location')
            ->map(fn ($v) => (float) $v);

        $recentTotal = $recentByLoc->sum();
        $priorTotal  = $priorByLoc->sum();

        if ($recentTotal <= 0 || $priorTotal <= 0) return;

        foreach ($recentByLoc as $location => $recentQty) {
            $priorQty = (float)($priorByLoc->get($location) ?? 0);
            if ($priorQty <= 0) continue;

            $recentShare = ($recentQty / $recentTotal) * 100;
            $priorShare  = ($priorQty / $priorTotal) * 100;
            $shift       = $recentShare - $priorShare;

            if (abs($shift) >= $pct) {
                $direction = $shift > 0 ? 'gained' : 'lost';
                $this->flag($tenantId, 'channel_mix_shift', 'medium', null, null, null,
                    "Location '{$location}' {$direction} " . round(abs($shift), 1) . " percentage points of sales share "
                    . "(now " . round($recentShare, 1) . "% vs prior " . round($priorShare, 1) . "%).",
                    ['location' => $location, 'recent_share_pct' => round($recentShare, 1), 'prior_share_pct' => round($priorShare, 1), 'shift_pct' => round($shift, 1)]
                );
            }
        }
    }

    // =========================================================================
    // INVENTORY & SUPPLY
    // =========================================================================

    private function detectStockoutRisk(int $tenantId): void
    {
        $levels = InventoryLevel::where('tenant_id', $tenantId)
            ->whereNotNull('reorder_point')
            ->where('reorder_point', '>', 0)
            ->whereColumn('on_hand_qty', '<=', 'reorder_point')
            ->get();

        foreach ($levels as $level) {
            $loc = $level->location ? " at {$level->location}" : '';
            $this->flag($tenantId, 'stockout_risk', 'high', $level->sku, $level->store_id, $level->product_id,
                "SKU {$level->sku}{$loc} is at stockout risk — on hand: {$level->on_hand_qty}, reorder point: {$level->reorder_point}.",
                ['on_hand_qty' => $level->on_hand_qty, 'reorder_point' => $level->reorder_point, 'location' => $level->location]
            );
        }
    }

    private function detectSafetyStockBreach(int $tenantId): void
    {
        $levels = InventoryLevel::where('tenant_id', $tenantId)
            ->whereNotNull('reorder_point')
            ->where('reorder_point', '>', 0)
            ->get()
            ->filter(fn ($l) => (float) $l->on_hand_qty < (float) $l->reorder_point * 0.5);

        foreach ($levels as $level) {
            $safetyProxy = round((float) $level->reorder_point * 0.5, 1);
            $loc = $level->location ? " at {$level->location}" : '';
            $this->flag($tenantId, 'safety_stock_breach', 'high', $level->sku, $level->store_id, $level->product_id,
                "SKU {$level->sku}{$loc} is critically low — on hand: {$level->on_hand_qty} (below safety proxy of {$safetyProxy}, 50% of reorder point {$level->reorder_point}).",
                ['on_hand_qty' => $level->on_hand_qty, 'reorder_point' => $level->reorder_point, 'safety_stock_proxy' => $safetyProxy, 'location' => $level->location]
            );
        }
    }

    private function detectDeadStock(int $tenantId, array $thresholds): void
    {
        $days      = (int)($thresholds['days'] ?? 30);
        $sinceDate = Carbon::today()->subDays($days)->format('Y-m-d');

        $inventoried = InventoryLevel::where('tenant_id', $tenantId)
            ->where('on_hand_qty', '>', 0)
            ->pluck('sku')
            ->unique();

        $activeSKUs = SalesTransaction::where('tenant_id', $tenantId)
            ->where('date', '>=', $sinceDate)
            ->pluck('sku')
            ->unique();

        foreach ($inventoried->diff($activeSKUs) as $sku) {
            $level = InventoryLevel::where('tenant_id', $tenantId)->where('sku', $sku)->first();
            $this->flag($tenantId, 'dead_stock', 'low', $sku, $level?->store_id, $level?->product_id,
                "SKU {$sku} has " . round((float)($level?->on_hand_qty ?? 0)) . " units in inventory but no sales in the last {$days} days.",
                ['on_hand_qty' => $level?->on_hand_qty, 'days_without_sales' => $days]
            );
        }
    }

    private function detectPhantomInventory(int $tenantId): void
    {
        $inventoriedSkus = InventoryLevel::where('tenant_id', $tenantId)
            ->where('on_hand_qty', '>', 0)
            ->pluck('sku')
            ->unique();

        $everSoldSkus = SalesTransaction::where('tenant_id', $tenantId)
            ->pluck('sku')
            ->unique();

        foreach ($inventoriedSkus->diff($everSoldSkus) as $sku) {
            $level = InventoryLevel::where('tenant_id', $tenantId)
                ->where('sku', $sku)
                ->orderByDesc('on_hand_qty')
                ->first();

            $this->flag($tenantId, 'phantom_inventory', 'medium', $sku, $level?->store_id, $level?->product_id,
                "SKU {$sku} has " . round((float)($level?->on_hand_qty ?? 0)) . " units in inventory but has never generated a sales transaction.",
                ['on_hand_qty' => $level?->on_hand_qty, 'location' => $level?->location]
            );
        }
    }

    private function detectMultiLocationImbalance(int $tenantId): void
    {
        $levelsBySku = InventoryLevel::where('tenant_id', $tenantId)
            ->whereNotNull('location')
            ->get()
            ->groupBy('sku');

        foreach ($levelsBySku as $sku => $levels) {
            if ($levels->count() < 2) continue;

            $stochedOut  = $levels->filter(fn ($l) =>
                (float) $l->on_hand_qty <= 0 ||
                ($l->reorder_point && (float) $l->on_hand_qty <= (float) $l->reorder_point)
            );
            $overstocked = $levels->filter(fn ($l) =>
                $l->reorder_point && (float) $l->on_hand_qty > (float) $l->reorder_point * 2
            );

            if ($stochedOut->isEmpty() || $overstocked->isEmpty()) continue;

            $product        = Product::where('tenant_id', $tenantId)->where('sku', $sku)->first();
            $stockedOutLoc  = $stochedOut->first()->location;
            $overstockedLoc = $overstocked->first()->location;
            $surplusQty     = round((float) $overstocked->first()->on_hand_qty);

            $this->flag($tenantId, 'multi_location_imbalance', 'medium', $sku, null, $product?->id,
                "SKU {$sku} is stocked out at '{$stockedOutLoc}' while '{$overstockedLoc}' has {$surplusQty} units — consider rebalancing.",
                [
                    'stocked_out_location'  => $stockedOutLoc,
                    'overstocked_location'  => $overstockedLoc,
                    'surplus_qty'           => $surplusQty,
                    'stocked_out_qty'       => $stochedOut->first()->on_hand_qty,
                ]
            );
        }
    }

    private function detectReorderPointStaleness(int $tenantId, array $thresholds): void
    {
        $days      = (int)($thresholds['days'] ?? 90);
        $cutoff    = Carbon::today()->subDays($days)->format('Y-m-d');
        $recentCut = Carbon::today()->subDays(30)->format('Y-m-d');

        $staleRecords = InventoryLevel::where('tenant_id', $tenantId)
            ->whereNotNull('reorder_point')
            ->where('reorder_point', '>', 0)
            ->whereNotNull('as_of_date')
            ->where('as_of_date', '<', $cutoff)
            ->get();

        $activeSkus = SalesTransaction::where('tenant_id', $tenantId)
            ->where('date', '>=', $recentCut)
            ->pluck('sku')
            ->unique();

        foreach ($staleRecords as $level) {
            if (!$activeSkus->contains($level->sku)) continue;

            $daysStale = Carbon::parse($level->as_of_date)->diffInDays(Carbon::today());
            $this->flag($tenantId, 'reorder_point_staleness', 'low', $level->sku, $level->store_id, $level->product_id,
                "SKU {$level->sku} has a reorder point of {$level->reorder_point} set {$daysStale} days ago — may not reflect current sales velocity.",
                ['reorder_point' => $level->reorder_point, 'as_of_date' => $level->as_of_date?->format('Y-m-d'), 'days_stale' => $daysStale, 'location' => $level->location]
            );
        }
    }

    private function detectInventoryShrinkage(int $tenantId, array $thresholds): void
    {
        $pct = (float)($thresholds['pct'] ?? 20);

        $pairs = InventoryLevel::where('tenant_id', $tenantId)
            ->whereNotNull('as_of_date')
            ->whereNotNull('location')
            ->selectRaw('sku, location')
            ->groupBy('sku', 'location')
            ->havingRaw('COUNT(*) >= 2')
            ->get();

        foreach ($pairs as $pair) {
            $snapshots = InventoryLevel::where('tenant_id', $tenantId)
                ->where('sku', $pair->sku)
                ->where('location', $pair->location)
                ->whereNotNull('as_of_date')
                ->orderBy('as_of_date', 'desc')
                ->limit(2)
                ->get();

            if ($snapshots->count() < 2) continue;

            $latest   = $snapshots->first();
            $previous = $snapshots->last();

            $prevQty   = (float) $previous->on_hand_qty;
            $latestQty = (float) $latest->on_hand_qty;

            if ($prevQty <= 0 || $latestQty >= $prevQty) continue;

            $salesInPeriod = (float) SalesTransaction::where('tenant_id', $tenantId)
                ->where('sku', $pair->sku)
                ->whereBetween('date', [
                    $previous->as_of_date->format('Y-m-d'),
                    $latest->as_of_date->format('Y-m-d'),
                ])
                ->sum('quantity');

            $expectedQty = $prevQty - $salesInPeriod;
            $unexplained = $expectedQty - $latestQty;

            if ($unexplained <= 0) continue;

            $shrinkagePct = ($unexplained / $prevQty) * 100;

            if ($shrinkagePct >= $pct) {
                $this->flag($tenantId, 'inventory_shrinkage', 'high', $pair->sku, $latest->store_id, $latest->product_id,
                    "SKU {$pair->sku} at '{$pair->location}' shows " . round($shrinkagePct) . "% inventory shrinkage — "
                    . round($unexplained) . " units unaccounted for between "
                    . $previous->as_of_date->format('Y-m-d') . " and " . $latest->as_of_date->format('Y-m-d') . ".",
                    [
                        'location'        => $pair->location,
                        'prev_qty'        => $prevQty,
                        'latest_qty'      => $latestQty,
                        'sales_in_period' => $salesInPeriod,
                        'unexplained'     => round($unexplained, 2),
                        'shrinkage_pct'   => round($shrinkagePct, 1),
                    ]
                );
            }
        }
    }

    // =========================================================================
    // PURCHASE ORDERS
    // =========================================================================

    private function detectPoOverdue(int $tenantId): void
    {
        $today = Carbon::today()->format('Y-m-d');

        $pos = PurchaseOrder::where('tenant_id', $tenantId)
            ->whereNotNull('expected_date')
            ->where('expected_date', '<', $today)
            ->whereNull('received_date')
            ->whereColumn('qty_received', '<', 'qty_ordered')
            ->get();

        foreach ($pos as $po) {
            $daysOverdue = Carbon::parse($po->expected_date)->diffInDays(Carbon::today());
            $this->flag($tenantId, 'po_overdue', 'medium', $po->sku, null, $po->product_id,
                "PO #{$po->po_number} from {$po->supplier} (SKU {$po->sku}) is {$daysOverdue} day(s) overdue "
                . "(expected: {$po->expected_date}, received: {$po->qty_received}/{$po->qty_ordered}).",
                ['po_number' => $po->po_number, 'supplier' => $po->supplier, 'days_overdue' => $daysOverdue, 'qty_ordered' => $po->qty_ordered, 'qty_received' => $po->qty_received]
            );
        }
    }

    private function detectReceivingDiscrepancy(int $tenantId, array $thresholds): void
    {
        $pct       = (float)($thresholds['pct'] ?? 20);
        $threshold = 1 - ($pct / 100);

        PurchaseOrder::where('tenant_id', $tenantId)
            ->whereNotNull('received_date')
            ->where('qty_ordered', '>', 0)
            ->get()
            ->filter(fn ($po) => (float) $po->qty_received < (float) $po->qty_ordered * $threshold)
            ->each(function ($po) use ($tenantId) {
                $receivedPct = round(((float) $po->qty_received / (float) $po->qty_ordered) * 100);
                $this->flag($tenantId, 'receiving_discrepancy', 'medium', $po->sku, null, $po->product_id,
                    "PO #{$po->po_number} from {$po->supplier} (SKU {$po->sku}) was closed with only {$receivedPct}% received "
                    . "({$po->qty_received} of {$po->qty_ordered} units).",
                    ['po_number' => $po->po_number, 'supplier' => $po->supplier, 'qty_ordered' => $po->qty_ordered, 'qty_received' => $po->qty_received, 'received_pct' => $receivedPct]
                );
            });
    }

    private function detectSupplierLeadTimeDrift(int $tenantId, array $thresholds): void
    {
        $pct       = (float)($thresholds['pct'] ?? 30);
        $recentCut = Carbon::today()->subDays(90)->format('Y-m-d');
        $histCut   = Carbon::today()->subDays(180)->format('Y-m-d');

        $recentPos = PurchaseOrder::where('tenant_id', $tenantId)
            ->whereNotNull('received_date')
            ->whereNotNull('order_date')
            ->where('order_date', '>=', $recentCut)
            ->get();

        $priorPos = PurchaseOrder::where('tenant_id', $tenantId)
            ->whereNotNull('received_date')
            ->whereNotNull('order_date')
            ->whereBetween('order_date', [$histCut, $recentCut])
            ->get();

        if ($recentPos->isEmpty() || $priorPos->isEmpty()) return;

        $leadDays = fn ($po) => Carbon::parse($po->order_date)->diffInDays(Carbon::parse($po->received_date));

        $recentBySupplier = $recentPos->groupBy('supplier')->map(fn ($pos) => $pos->avg($leadDays));
        $priorBySupplier  = $priorPos->groupBy('supplier')->map(fn ($pos) => $pos->avg($leadDays));

        foreach ($recentBySupplier as $supplier => $recentAvg) {
            $priorAvg = (float)($priorBySupplier->get($supplier) ?? 0);
            if ($priorAvg <= 0) continue;

            $driftPct = (($recentAvg - $priorAvg) / $priorAvg) * 100;

            if ($driftPct >= $pct) {
                $this->flag($tenantId, 'supplier_lead_time_drift', 'medium', null, null, null,
                    "Supplier '{$supplier}' average lead time grew " . round($driftPct) . "% "
                    . "(now " . round($recentAvg, 1) . " days vs prior " . round($priorAvg, 1) . " days).",
                    ['supplier' => $supplier, 'recent_avg_days' => round($recentAvg, 1), 'prior_avg_days' => round($priorAvg, 1), 'drift_pct' => round($driftPct, 1)]
                );
            }
        }
    }

    private function detectCostSpike(int $tenantId, array $thresholds): void
    {
        $pct = (float)($thresholds['pct'] ?? 25);

        $allPos = PurchaseOrder::where('tenant_id', $tenantId)
            ->whereNotNull('unit_cost')
            ->where('unit_cost', '>', 0)
            ->orderByDesc('order_date')
            ->get()
            ->groupBy('sku');

        foreach ($allPos as $sku => $pos) {
            if ($pos->count() < 2) continue;

            $latest  = $pos->first();
            $history = $pos->slice(1);

            $histAvg    = (float) $history->avg('unit_cost');
            if ($histAvg <= 0) continue;

            $latestCost = (float) $latest->unit_cost;
            $spikePct   = (($latestCost - $histAvg) / $histAvg) * 100;

            if ($spikePct >= $pct) {
                $product = Product::where('tenant_id', $tenantId)->where('sku', $sku)->first();
                $this->flag($tenantId, 'cost_spike', 'high', $sku, null, $product?->id,
                    "PO #{$latest->po_number} from {$latest->supplier} shows SKU {$sku} unit cost at \$" . round($latestCost, 2)
                    . " — " . round($spikePct) . "% above historical avg of \$" . round($histAvg, 2) . ".",
                    ['supplier' => $latest->supplier, 'po_number' => $latest->po_number, 'latest_cost' => $latestCost, 'historical_avg' => round($histAvg, 2), 'spike_pct' => round($spikePct, 1)]
                );
            }
        }
    }

    // =========================================================================
    // FINANCIAL
    // =========================================================================

    private function detectPriceAnomaly(int $tenantId, array $thresholds): void
    {
        $pct        = (float)($thresholds['pct'] ?? 25);
        $recentDate = Carbon::today()->subDays(30)->format('Y-m-d');

        $avgPrices = SalesTransaction::where('tenant_id', $tenantId)
            ->whereNotNull('unit_price')
            ->where('unit_price', '>', 0)
            ->selectRaw('sku, AVG(unit_price) as avg_price, COUNT(*) as cnt')
            ->groupBy('sku')
            ->having('cnt', '>=', 3)
            ->pluck('avg_price', 'sku')
            ->map(fn ($v) => (float) $v);

        $recent = SalesTransaction::where('tenant_id', $tenantId)
            ->where('date', '>=', $recentDate)
            ->whereNotNull('unit_price')
            ->where('unit_price', '>', 0)
            ->get()
            ->groupBy('sku');

        foreach ($recent as $sku => $txs) {
            $avg = $avgPrices->get($sku);
            if (!$avg) continue;

            $worst = null;
            $worstDeviation = 0;

            foreach ($txs as $tx) {
                $deviation = abs(((float) $tx->unit_price - $avg) / $avg) * 100;
                if ($deviation >= $pct && $deviation > $worstDeviation) {
                    $worst          = $tx;
                    $worstDeviation = $deviation;
                }
            }

            if ($worst) {
                $direction = (float) $worst->unit_price > $avg ? 'above' : 'below';
                $count     = $txs->filter(fn ($tx) => abs(((float) $tx->unit_price - $avg) / $avg) * 100 >= $pct)->count();
                $this->flag($tenantId, 'price_anomaly', 'low', $sku, $worst->store_id, $worst->product_id,
                    "SKU {$sku} has {$count} recent transaction(s) with price anomalies — worst: "
                    . round($worst->unit_price, 2) . " is " . round($worstDeviation) . "% {$direction} the avg of " . round($avg, 2) . ".",
                    ['avg_price' => round($avg, 4), 'worst_price' => $worst->unit_price, 'deviation_pct' => round($worstDeviation, 1), 'count' => $count]
                );
            }
        }
    }

    private function detectMarginErosion(int $tenantId): void
    {
        $recentDate = Carbon::today()->subDays(30)->format('Y-m-d');

        $products = Product::where('tenant_id', $tenantId)
            ->whereNotNull('unit_cost')
            ->where('unit_cost', '>', 0)
            ->pluck('unit_cost', 'sku')
            ->map(fn ($v) => (float) $v);

        if ($products->isEmpty()) return;

        $recent = SalesTransaction::where('tenant_id', $tenantId)
            ->where('date', '>=', $recentDate)
            ->whereNotNull('unit_price')
            ->where('unit_price', '>', 0)
            ->whereIn('sku', $products->keys())
            ->get()
            ->groupBy('sku');

        foreach ($recent as $sku => $txs) {
            $cost      = $products->get($sku);
            $belowCost = $txs->filter(fn ($tx) => (float) $tx->unit_price < $cost);

            if ($belowCost->isEmpty()) continue;

            $worst = $belowCost->sortBy('unit_price')->first();
            $this->flag($tenantId, 'margin_erosion', 'high', $sku, $worst->store_id, $worst->product_id,
                "SKU {$sku} has {$belowCost->count()} recent transaction(s) sold below cost — "
                . "worst: sold at \$" . round($worst->unit_price, 2) . " vs unit cost of \$" . round($cost, 2) . ".",
                ['unit_cost' => $cost, 'worst_sale_price' => $worst->unit_price, 'count' => $belowCost->count()]
            );
        }
    }

    private function detectDiscountSignal(int $tenantId): void
    {
        $products = Product::where('tenant_id', $tenantId)
            ->whereNotNull('unit_cost')
            ->whereNotNull('selling_price')
            ->where('unit_cost', '>', 0)
            ->where('selling_price', '>', 0)
            ->get()
            ->filter(fn ($p) => (float) $p->selling_price < (float) $p->unit_cost);

        foreach ($products as $product) {
            $lossPct = abs(((float) $product->selling_price - (float) $product->unit_cost) / (float) $product->unit_cost) * 100;
            $this->flag($tenantId, 'discount_signal', 'medium', $product->sku, null, $product->id,
                "SKU {$product->sku} list price \$" . round($product->selling_price, 2)
                . " is below unit cost \$" . round($product->unit_cost, 2)
                . " — a built-in loss of " . round($lossPct, 1) . "% per unit sold.",
                ['selling_price' => $product->selling_price, 'unit_cost' => $product->unit_cost, 'loss_pct' => round($lossPct, 1)]
            );
        }
    }

    private function detectRevenueConcentrationRisk(int $tenantId, array $thresholds): void
    {
        $pct   = (float)($thresholds['pct'] ?? 80);
        $days  = (int)($thresholds['days'] ?? 90);
        $since = Carbon::today()->subDays($days)->format('Y-m-d');

        $revenue = SalesTransaction::where('tenant_id', $tenantId)
            ->where('date', '>=', $since)
            ->whereNotNull('total_amount')
            ->where('total_amount', '>', 0)
            ->selectRaw('sku, SUM(total_amount) as revenue')
            ->groupBy('sku')
            ->orderByDesc('revenue')
            ->get();

        if ($revenue->isEmpty()) return;

        $total    = (float) $revenue->sum('revenue');
        if ($total <= 0) return;

        $top      = $revenue->take(3);
        $topTotal = (float) $top->sum('revenue');
        $topPct   = ($topTotal / $total) * 100;

        if ($topPct >= $pct) {
            $topSkus = $top->pluck('sku')->implode(', ');
            $this->flag($tenantId, 'revenue_concentration_risk', 'medium', null, null, null,
                "Top 3 SKUs ({$topSkus}) account for " . round($topPct, 1) . "% of revenue in the last {$days} days — high concentration risk.",
                ['top_skus' => $top->pluck('sku')->toArray(), 'concentration_pct' => round($topPct, 1), 'top_revenue' => round($topTotal, 2), 'total_revenue' => round($total, 2), 'days' => $days]
            );
        }
    }

    private function detectSlowMovingCapital(int $tenantId, array $thresholds): void
    {
        $days     = (int)($thresholds['days'] ?? 60);
        $minValue = (float)($thresholds['min_value'] ?? 1000);
        $since    = Carbon::today()->subDays($days)->format('Y-m-d');

        $products = Product::where('tenant_id', $tenantId)
            ->whereNotNull('unit_cost')
            ->where('unit_cost', '>', 0)
            ->pluck('unit_cost', 'sku')
            ->map(fn ($v) => (float) $v);

        if ($products->isEmpty()) return;

        $inventory = InventoryLevel::where('tenant_id', $tenantId)
            ->where('on_hand_qty', '>', 0)
            ->whereIn('sku', $products->keys())
            ->get()
            ->groupBy('sku');

        $activeSkus = SalesTransaction::where('tenant_id', $tenantId)
            ->where('date', '>=', $since)
            ->pluck('sku')
            ->unique();

        foreach ($inventory as $sku => $levels) {
            if ($activeSkus->contains($sku)) continue;

            $totalQty   = (float) $levels->sum('on_hand_qty');
            $unitCost   = $products->get($sku);
            $totalValue = $totalQty * $unitCost;

            if ($totalValue < $minValue) continue;

            $product = Product::where('tenant_id', $tenantId)->where('sku', $sku)->first();
            $this->flag($tenantId, 'slow_moving_capital', 'medium', $sku, null, $product?->id,
                "SKU {$sku} has \$" . number_format($totalValue, 2) . " tied up in inventory "
                . "(" . round($totalQty) . " units × \$" . round($unitCost, 2) . ") with no sales in the last {$days} days.",
                ['on_hand_qty' => $totalQty, 'unit_cost' => $unitCost, 'inventory_value' => round($totalValue, 2), 'days_without_sales' => $days]
            );
        }
    }

    // =========================================================================
    // STORE PERFORMANCE
    // =========================================================================

    private function detectStoreOutlier(int $tenantId, array $thresholds): void
    {
        $pct   = (float)($thresholds['pct'] ?? 50);
        $days  = (int)($thresholds['days'] ?? 7);
        $since = Carbon::today()->subDays($days)->format('Y-m-d');

        $salesBySkuLocation = SalesTransaction::where('tenant_id', $tenantId)
            ->where('date', '>=', $since)
            ->whereNotNull('location')
            ->selectRaw('sku, location, SUM(quantity) as qty')
            ->groupBy('sku', 'location')
            ->get()
            ->groupBy('sku');

        foreach ($salesBySkuLocation as $sku => $rows) {
            if ($rows->count() < 2) continue;

            $qtys = $rows->pluck('qty')->map(fn ($v) => (float) $v);
            $avg  = $qtys->average();
            if ($avg <= 0) continue;

            foreach ($rows as $row) {
                $qty     = (float) $row->qty;
                $dropPct = (($avg - $qty) / $avg) * 100;

                if ($dropPct >= $pct) {
                    $this->flag($tenantId, 'store_outlier', 'medium', $sku, null, null,
                        "SKU {$sku} at '{$row->location}' sold " . round($qty) . " units in the last {$days} days — "
                        . round($dropPct) . "% below the cross-location average of " . round($avg) . " units.",
                        ['location' => $row->location, 'location_qty' => $qty, 'avg_qty' => round($avg, 1), 'drop_pct' => round($dropPct, 1), 'days' => $days]
                    );
                }
            }
        }
    }

    // =========================================================================
    // OPERATIONAL / DATA QUALITY
    // =========================================================================

    private function detectImportFrequencyGap(int $tenantId, array $thresholds): void
    {
        $days = (int)($thresholds['days'] ?? 7);

        $latestImport = Import::where('tenant_id', $tenantId)
            ->whereIn('status', [Import::STATUS_COMPLETED, Import::STATUS_COMPLETED_WITH_ERRORS])
            ->orderByDesc('updated_at')
            ->first();

        if (!$latestImport) {
            $this->flag($tenantId, 'import_frequency_gap', 'medium', null, null, null,
                "No data has ever been successfully imported for this tenant.",
                ['days_threshold' => $days]
            );
            return;
        }

        $daysSince = Carbon::parse($latestImport->updated_at)->diffInDays(Carbon::now());

        if ($daysSince >= $days) {
            $lastDate = Carbon::parse($latestImport->updated_at)->format('Y-m-d');
            $this->flag($tenantId, 'import_frequency_gap', 'medium', null, null, null,
                "No data import completed in {$daysSince} day(s) — last import was {$lastDate}.",
                ['days_since_last_import' => $daysSince, 'last_import_at' => $lastDate, 'days_threshold' => $days]
            );
        }
    }

    private function detectDuplicateTransactionIds(int $tenantId): void
    {
        $duplicates = SalesTransaction::where('tenant_id', $tenantId)
            ->whereNotNull('transaction_id')
            ->selectRaw('transaction_id, COUNT(*) as cnt, MIN(sku) as sku')
            ->groupBy('transaction_id')
            ->having('cnt', '>', 1)
            ->get();

        foreach ($duplicates as $dup) {
            $this->flag($tenantId, 'duplicate_transaction_ids', 'high', $dup->sku, null, null,
                "Transaction ID '{$dup->transaction_id}' appears {$dup->cnt} times in sales data — possible duplicate import.",
                ['transaction_id' => $dup->transaction_id, 'count' => $dup->cnt]
            );
        }
    }

    private function detectSkuMasterDrift(int $tenantId): void
    {
        $knownSkus = Product::where('tenant_id', $tenantId)->pluck('sku')->unique();

        $salesSkus     = SalesTransaction::where('tenant_id', $tenantId)->pluck('sku')->unique();
        $inventorySkus = InventoryLevel::where('tenant_id', $tenantId)->pluck('sku')->unique();
        $allDataSkus   = $salesSkus->merge($inventorySkus)->unique();

        foreach ($allDataSkus->diff($knownSkus) as $sku) {
            $inSales     = $salesSkus->contains($sku);
            $inInventory = $inventorySkus->contains($sku);
            $sources     = array_values(array_filter([
                $inSales     ? 'sales' : null,
                $inInventory ? 'inventory' : null,
            ]));

            $this->flag($tenantId, 'sku_master_drift', 'low', $sku, null, null,
                "SKU {$sku} appears in " . implode(' and ', $sources) . " data but has no product master record.",
                ['sku' => $sku, 'found_in' => $sources]
            );
        }
    }

    private function detectLocationProliferation(int $tenantId, array $thresholds): void
    {
        $days  = (int)($thresholds['days'] ?? 7);
        $since = Carbon::today()->subDays($days)->format('Y-m-d');

        $newStores = Store::where('tenant_id', $tenantId)
            ->where('created_at', '>=', $since)
            ->whereNull('code')
            ->whereNull('city')
            ->whereNull('address')
            ->get();

        foreach ($newStores as $store) {
            $this->flag($tenantId, 'location_proliferation', 'low', null, $store->id, null,
                "New location '{$store->name}' was auto-created from an import with no enrichment data — verify it is not a duplicate or typo.",
                ['store_name' => $store->name, 'created_at' => $store->created_at?->format('Y-m-d')]
            );
        }
    }

    // =========================================================================
    // SHARED HELPERS
    // =========================================================================

    /**
     * Returns [recentSalesBySku, historicalSalesBySku, numComparablePeriods]
     */
    private function salesComparison(int $tenantId, int $days): array
    {
        $recentStart = Carbon::today()->subDays($days)->format('Y-m-d');
        $histEnd     = Carbon::today()->subDays($days)->format('Y-m-d');
        $histStart   = Carbon::today()->subDays($days + 28)->format('Y-m-d');

        $recent = SalesTransaction::where('tenant_id', $tenantId)
            ->where('date', '>=', $recentStart)
            ->selectRaw('sku, SUM(quantity) as qty')
            ->groupBy('sku')
            ->pluck('qty', 'sku')
            ->map(fn ($v) => (float) $v);

        $historical = SalesTransaction::where('tenant_id', $tenantId)
            ->whereBetween('date', [$histStart, $histEnd])
            ->selectRaw('sku, SUM(quantity) as qty')
            ->groupBy('sku')
            ->pluck('qty', 'sku')
            ->map(fn ($v) => (float) $v);

        $numPeriods = max(1, (int) round(28 / $days));

        return [$recent, $historical, $numPeriods];
    }

    private function flag(
        int $tenantId,
        string $ruleType,
        string $severity,
        ?string $sku,
        ?int $storeId,
        ?int $productId,
        string $description,
        array $context = []
    ): void {
        Anomaly::create([
            'tenant_id'   => $tenantId,
            'rule_type'   => $ruleType,
            'severity'    => $severity,
            'sku'         => $sku,
            'store_id'    => $storeId,
            'product_id'  => $productId,
            'description' => $description,
            'context'     => $context,
            'detected_at' => now(),
        ]);
    }
}
