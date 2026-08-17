<?php

namespace App\Services\Anomaly;

use App\Models\Anomaly;
use App\Models\AnomalySetting;
use App\Models\InventoryLevel;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AnomalyDetectionService
{
    /**
     * Run all enabled rules for a tenant and store results in the anomalies table.
     */
    public function runForTenant(int $tenantId): void
    {
        // Seed settings if this is the first run
        AnomalySetting::seedForTenant($tenantId);

        $settings = AnomalySetting::where('tenant_id', $tenantId)
            ->get()
            ->keyBy('rule_type');

        $rules = [
            'sales_spike'           => fn () => $this->detectSalesSpike($tenantId, $settings->get('sales_spike')?->getEffectiveThresholds() ?? []),
            'sales_drop'            => fn () => $this->detectSalesDrop($tenantId, $settings->get('sales_drop')?->getEffectiveThresholds() ?? []),
            'stockout_risk'         => fn () => $this->detectStockoutRisk($tenantId),
            'dead_stock'            => fn () => $this->detectDeadStock($tenantId, $settings->get('dead_stock')?->getEffectiveThresholds() ?? []),
            'po_overdue'            => fn () => $this->detectPoOverdue($tenantId),
            'price_anomaly'         => fn () => $this->detectPriceAnomaly($tenantId, $settings->get('price_anomaly')?->getEffectiveThresholds() ?? []),
            'receiving_discrepancy' => fn () => $this->detectReceivingDiscrepancy($tenantId, $settings->get('receiving_discrepancy')?->getEffectiveThresholds() ?? []),
            'margin_erosion'        => fn () => $this->detectMarginErosion($tenantId),
            'store_outlier'         => fn () => $this->detectStoreOutlier($tenantId, $settings->get('store_outlier')?->getEffectiveThresholds() ?? []),
            'inventory_shrinkage'   => fn () => $this->detectInventoryShrinkage($tenantId, $settings->get('inventory_shrinkage')?->getEffectiveThresholds() ?? []),
        ];

        foreach ($rules as $ruleType => $detector) {
            $setting = $settings->get($ruleType);
            if (!$setting || !$setting->enabled) {
                continue;
            }

            // Clear previous open (non-dismissed) anomalies for this rule before re-running
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

    // ─────────────────────────────────────────────────────────────────────────
    // Rule 1 – Sales Spike
    // ─────────────────────────────────────────────────────────────────────────

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

    // ─────────────────────────────────────────────────────────────────────────
    // Rule 2 – Sales Drop
    // ─────────────────────────────────────────────────────────────────────────

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

    // ─────────────────────────────────────────────────────────────────────────
    // Rule 3 – Stockout Risk
    // ─────────────────────────────────────────────────────────────────────────

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

    // ─────────────────────────────────────────────────────────────────────────
    // Rule 4 – Dead Stock
    // ─────────────────────────────────────────────────────────────────────────

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
                "SKU {$sku} has " . round($level?->on_hand_qty ?? 0) . " units in inventory but no sales in the last {$days} days.",
                ['on_hand_qty' => $level?->on_hand_qty, 'days_without_sales' => $days]
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Rule 5 – PO Overdue
    // ─────────────────────────────────────────────────────────────────────────

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

    // ─────────────────────────────────────────────────────────────────────────
    // Rule 6 – Price Anomaly (flagged per SKU, not per transaction)
    // ─────────────────────────────────────────────────────────────────────────

    private function detectPriceAnomaly(int $tenantId, array $thresholds): void
    {
        $pct        = (float)($thresholds['pct'] ?? 25);
        $recentDate = Carbon::today()->subDays(30)->format('Y-m-d');

        // Historical average price per SKU (min 3 transactions to establish baseline)
        $avgPrices = SalesTransaction::where('tenant_id', $tenantId)
            ->whereNotNull('unit_price')
            ->where('unit_price', '>', 0)
            ->selectRaw('sku, AVG(unit_price) as avg_price, COUNT(*) as cnt')
            ->groupBy('sku')
            ->having('cnt', '>=', 3)
            ->pluck('avg_price', 'sku')
            ->map(fn ($v) => (float) $v);

        // Recent transactions grouped by SKU — keep only the worst offender per SKU
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
                    "SKU {$sku} has {$count} recent transaction(s) with price anomalies — worst case: "
                    . round($worst->unit_price, 2) . " is " . round($worstDeviation) . "% {$direction} the avg of " . round($avg, 2) . ".",
                    ['avg_price' => round($avg, 4), 'worst_price' => $worst->unit_price, 'deviation_pct' => round($worstDeviation, 1), 'count' => $count]
                );
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Rule 7 – Receiving Discrepancy
    // ─────────────────────────────────────────────────────────────────────────

    private function detectReceivingDiscrepancy(int $tenantId, array $thresholds): void
    {
        $pct       = (float)($thresholds['pct'] ?? 20);
        $threshold = 1 - ($pct / 100); // e.g. 0.80 = must receive at least 80%

        PurchaseOrder::where('tenant_id', $tenantId)
            ->whereNotNull('received_date')
            ->where('qty_ordered', '>', 0)
            ->get()
            ->filter(fn ($po) => (float) $po->qty_received < (float) $po->qty_ordered * $threshold)
            ->each(function ($po) use ($tenantId, $pct) {
                $receivedPct = round(((float) $po->qty_received / (float) $po->qty_ordered) * 100);
                $this->flag($tenantId, 'receiving_discrepancy', 'medium', $po->sku, null, $po->product_id,
                    "PO #{$po->po_number} from {$po->supplier} (SKU {$po->sku}) was closed with only {$receivedPct}% received "
                    . "({$po->qty_received} of {$po->qty_ordered} units).",
                    ['po_number' => $po->po_number, 'supplier' => $po->supplier, 'qty_ordered' => $po->qty_ordered, 'qty_received' => $po->qty_received, 'received_pct' => $receivedPct]
                );
            });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Rule 8 – Margin Erosion (flagged per SKU)
    // ─────────────────────────────────────────────────────────────────────────

    private function detectMarginErosion(int $tenantId): void
    {
        $recentDate = Carbon::today()->subDays(30)->format('Y-m-d');

        // Load recent sales and products separately to avoid complex SQLite joins
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
            $cost     = $products->get($sku);
            $belowCost = $txs->filter(fn ($tx) => (float) $tx->unit_price < $cost);

            if ($belowCost->isEmpty()) continue;

            $worst = $belowCost->sortBy('unit_price')->first();
            $this->flag($tenantId, 'margin_erosion', 'high', $sku, $worst->store_id, $worst->product_id,
                "SKU {$sku} has {$belowCost->count()} recent transaction(s) sold below cost — "
                . "worst: sold at " . round($worst->unit_price, 2) . " vs unit cost of " . round($cost, 2) . ".",
                ['unit_cost' => $cost, 'worst_sale_price' => $worst->unit_price, 'count' => $belowCost->count()]
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Rule 9 – Store Outlier
    // ─────────────────────────────────────────────────────────────────────────

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
                $qty      = (float) $row->qty;
                $dropPct  = (($avg - $qty) / $avg) * 100;

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

    // ─────────────────────────────────────────────────────────────────────────
    // Rule 10 – Inventory Shrinkage
    // ─────────────────────────────────────────────────────────────────────────

    private function detectInventoryShrinkage(int $tenantId, array $thresholds): void
    {
        $pct = (float)($thresholds['pct'] ?? 20);

        // Find SKU+location pairs with at least 2 dated snapshots
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

            $expectedQty    = $prevQty - $salesInPeriod;
            $unexplained    = $expectedQty - $latestQty;

            if ($unexplained <= 0) continue;

            $shrinkagePct = ($unexplained / $prevQty) * 100;

            if ($shrinkagePct >= $pct) {
                $this->flag($tenantId, 'inventory_shrinkage', 'high', $pair->sku, $latest->store_id, $latest->product_id,
                    "SKU {$pair->sku} at '{$pair->location}' shows " . round($shrinkagePct) . "% inventory shrinkage — "
                    . round($unexplained) . " units unaccounted for between "
                    . $previous->as_of_date->format('Y-m-d') . " and " . $latest->as_of_date->format('Y-m-d') . ".",
                    [
                        'location'       => $pair->location,
                        'prev_qty'       => $prevQty,
                        'latest_qty'     => $latestQty,
                        'sales_in_period'=> $salesInPeriod,
                        'unexplained'    => round($unexplained, 2),
                        'shrinkage_pct'  => round($shrinkagePct, 1),
                    ]
                );
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Shared helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Returns [recentSalesBySkU, historicalSalesBySku, numComparablePeriods]
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
