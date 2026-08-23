<?php

namespace App\Services\Anomaly;

use App\Models\Anomaly;
use App\Models\IngestionRun;
use App\Models\Investigation;
use App\Models\InvestigationEvidence;
use App\Models\InventoryLevel;
use App\Models\InventorySnapshot;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesTransaction;
use App\Models\SkuBaseline;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * EvidenceCollectorService — M17
 *
 * Gathers deterministic evidence for every anomaly in an Investigation and
 * stores it as InvestigationEvidence rows.
 *
 * The AI (M19) reads these rows instead of issuing its own DB queries.
 * One "evidence package" per investigation = one AI call.
 *
 * Evidence is rule-specific. Each private collect*() method handles a family
 * of rules and records the data points most relevant to that family.
 */
class EvidenceCollectorService
{
    private const SALES_LOOKBACK_DAYS     = 14;
    private const HISTORY_LOOKBACK_DAYS   = 90;

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Collect and persist all evidence for an investigation.
     * Idempotent: re-running replaces nothing — evidence rows accumulate;
     * duplicates are avoided via firstOrCreate on (investigation_id, anomaly_id, label).
     */
    public function collectForInvestigation(Investigation $investigation): void
    {
        $anomalies = $investigation->anomalies()->get();

        foreach ($anomalies as $anomaly) {
            try {
                $this->collectForAnomaly($investigation, $anomaly);
            } catch (\Throwable $e) {
                Log::error("[M17/evidence] investigation={$investigation->id} anomaly={$anomaly->id}: {$e->getMessage()}");
            }
        }

        Log::info("[M17] Evidence collected for investigation #{$investigation->id} ({$anomalies->count()} anomalies)");
    }

    // =========================================================================
    // DISPATCH BY RULE FAMILY
    // =========================================================================

    private function collectForAnomaly(Investigation $investigation, Anomaly $anomaly): void
    {
        match (true) {
            in_array($anomaly->rule_type, ['sales_spike', 'sales_drop', 'demand_seasonality_breach', 'demand_erosion'])
                => $this->collectSalesEvidence($investigation, $anomaly),

            in_array($anomaly->rule_type, ['cannibalization_signal', 'channel_mix_shift', 'store_outlier'])
                => $this->collectCrossSkuEvidence($investigation, $anomaly),

            in_array($anomaly->rule_type, ['return_rate_spike'])
                => $this->collectReturnEvidence($investigation, $anomaly),

            in_array($anomaly->rule_type, ['stockout_risk', 'safety_stock_breach', 'dead_stock',
                                            'phantom_inventory', 'multi_location_imbalance',
                                            'inventory_shrinkage', 'reorder_point_staleness',
                                            'negative_inventory', 'overstock', 'cumulative_shrink'])
                => $this->collectInventoryEvidence($investigation, $anomaly),

            in_array($anomaly->rule_type, ['po_overdue', 'receiving_discrepancy',
                                            'supplier_lead_time_drift', 'cost_spike',
                                            'po_late_receipt', 'supplier_fill_rate'])
                => $this->collectPoEvidence($investigation, $anomaly),

            in_array($anomaly->rule_type, ['price_anomaly', 'margin_erosion', 'discount_signal',
                                            'revenue_concentration_risk', 'slow_moving_capital'])
                => $this->collectFinancialEvidence($investigation, $anomaly),

            in_array($anomaly->rule_type, ['import_frequency_gap', 'duplicate_transaction_ids',
                                            'sku_master_drift', 'location_proliferation'])
                => $this->collectDataQualityEvidence($investigation, $anomaly),

            default => null,
        };
    }

    // =========================================================================
    // SALES EVIDENCE (sales_spike, sales_drop, demand_seasonality_breach)
    // =========================================================================

    private function collectSalesEvidence(Investigation $investigation, Anomaly $anomaly): void
    {
        $tenantId = $investigation->tenant_id;
        $sku      = $anomaly->sku;
        $since    = Carbon::today()->subDays(self::SALES_LOOKBACK_DAYS)->format('Y-m-d');

        // Recent daily sales series
        $dailySales = SalesTransaction::where('tenant_id', $tenantId)
            ->where('sku', $sku)
            ->where('date', '>=', $since)
            ->where('quantity', '>', 0)
            ->when($anomaly->store_id, fn ($q) => $q->where('store_id', $anomaly->store_id))
            ->selectRaw("TO_CHAR(date::date, 'YYYY-MM-DD') as day, SUM(quantity) as qty")
            ->groupByRaw("TO_CHAR(date::date, 'YYYY-MM-DD')")
            ->orderBy('day')
            ->get()
            ->pluck('qty', 'day')
            ->toArray();

        $this->record($investigation, $anomaly, [
            'evidence_type' => InvestigationEvidence::TYPE_DATA_POINT,
            'source'        => 'sales_transactions',
            'label'         => "Daily sales last " . self::SALES_LOOKBACK_DAYS . " days",
            'value_json'    => $dailySales,
            'unit'          => 'units/day',
            'direction'     => InvestigationEvidence::DIRECTION_SUPPORTS,
            'strength'      => InvestigationEvidence::STRENGTH_STRONG,
            'observed_at'   => now(),
        ]);

        // Total units in window
        $totalRecent = array_sum($dailySales);
        $this->record($investigation, $anomaly, [
            'evidence_type' => InvestigationEvidence::TYPE_STAT,
            'source'        => 'sales_transactions',
            'label'         => "Total units sold (last " . self::SALES_LOOKBACK_DAYS . " days)",
            'value_numeric' => $totalRecent,
            'unit'          => 'units',
            'direction'     => InvestigationEvidence::DIRECTION_SUPPORTS,
            'strength'      => InvestigationEvidence::STRENGTH_STRONG,
            'observed_at'   => now(),
        ]);

        // Baseline mean and stddev if available
        $baseline = SkuBaseline::where('tenant_id', $tenantId)
            ->where('sku', $sku)
            ->where('rule_type', $anomaly->rule_type)
            ->where('metric', 'daily_sales_qty')
            ->when($anomaly->store_id,
                fn ($q) => $q->where('store_id', $anomaly->store_id),
                fn ($q) => $q->whereNull('store_id')
            )
            ->first();

        if ($baseline) {
            $this->record($investigation, $anomaly, [
                'evidence_type' => InvestigationEvidence::TYPE_STAT,
                'source'        => 'sku_baselines',
                'label'         => "Baseline mean daily sales (90-day)",
                'value_numeric' => $baseline->baseline_mean,
                'unit'          => 'units/day',
                'direction'     => InvestigationEvidence::DIRECTION_NEUTRAL,
                'strength'      => InvestigationEvidence::STRENGTH_STRONG,
                'observed_at'   => $baseline->computed_at,
            ]);

            $this->record($investigation, $anomaly, [
                'evidence_type' => InvestigationEvidence::TYPE_STAT,
                'source'        => 'sku_baselines',
                'label'         => "Baseline stddev daily sales (90-day)",
                'value_numeric' => $baseline->baseline_stddev,
                'unit'          => 'units/day',
                'direction'     => InvestigationEvidence::DIRECTION_NEUTRAL,
                'strength'      => InvestigationEvidence::STRENGTH_MODERATE,
                'observed_at'   => $baseline->computed_at,
            ]);

            // z-score of most recent day
            if (!empty($dailySales)) {
                $latestQty = (float) end($dailySales);
                $zScore    = $baseline->baseline_stddev > 0
                    ? ($latestQty - $baseline->baseline_mean) / $baseline->baseline_stddev
                    : 0.0;

                $direction = $zScore > 0
                    ? InvestigationEvidence::DIRECTION_SUPPORTS
                    : InvestigationEvidence::DIRECTION_CONTRADICTS;

                $this->record($investigation, $anomaly, [
                    'evidence_type' => InvestigationEvidence::TYPE_CALCULATION,
                    'source'        => 'sku_baselines',
                    'label'         => "Z-score of most recent day vs baseline",
                    'value_numeric' => round($zScore, 3),
                    'unit'          => 'σ',
                    'direction'     => $direction,
                    'strength'      => InvestigationEvidence::STRENGTH_STRONG,
                    'observed_at'   => now(),
                ]);
            }
        }
    }

    // =========================================================================
    // CROSS-SKU / LOCATION EVIDENCE
    // =========================================================================

    private function collectCrossSkuEvidence(Investigation $investigation, Anomaly $anomaly): void
    {
        $tenantId = $investigation->tenant_id;
        $since    = Carbon::today()->subDays(30)->format('Y-m-d');

        // Sales by location for this SKU
        $byLocation = SalesTransaction::where('tenant_id', $tenantId)
            ->where('sku', $anomaly->sku)
            ->where('date', '>=', $since)
            ->where('quantity', '>', 0)
            ->selectRaw('location, SUM(quantity) as qty')
            ->groupBy('location')
            ->orderByDesc('qty')
            ->get()
            ->pluck('qty', 'location')
            ->toArray();

        $this->record($investigation, $anomaly, [
            'evidence_type' => InvestigationEvidence::TYPE_DATA_POINT,
            'source'        => 'sales_transactions',
            'label'         => "Units sold by location (last 30 days)",
            'value_json'    => $byLocation,
            'unit'          => 'units',
            'direction'     => InvestigationEvidence::DIRECTION_SUPPORTS,
            'strength'      => InvestigationEvidence::STRENGTH_STRONG,
            'observed_at'   => now(),
        ]);
    }

    // =========================================================================
    // RETURN EVIDENCE
    // =========================================================================

    private function collectReturnEvidence(Investigation $investigation, Anomaly $anomaly): void
    {
        $tenantId = $investigation->tenant_id;
        $since    = Carbon::today()->subDays(30)->format('Y-m-d');

        $returns = SalesTransaction::where('tenant_id', $tenantId)
            ->where('sku', $anomaly->sku)
            ->where('date', '>=', $since)
            ->where('quantity', '<', 0)
            ->selectRaw('SUM(ABS(quantity)) as return_qty')
            ->value('return_qty') ?? 0;

        $sales = SalesTransaction::where('tenant_id', $tenantId)
            ->where('sku', $anomaly->sku)
            ->where('date', '>=', $since)
            ->where('quantity', '>', 0)
            ->selectRaw('SUM(quantity) as sales_qty')
            ->value('sales_qty') ?? 0;

        $returnRate = $sales > 0 ? round(($returns / $sales) * 100, 2) : null;

        $this->record($investigation, $anomaly, [
            'evidence_type' => InvestigationEvidence::TYPE_STAT,
            'source'        => 'sales_transactions',
            'label'         => "Return units (last 30 days)",
            'value_numeric' => $returns,
            'unit'          => 'units',
            'direction'     => InvestigationEvidence::DIRECTION_SUPPORTS,
            'strength'      => InvestigationEvidence::STRENGTH_STRONG,
            'observed_at'   => now(),
        ]);

        if ($returnRate !== null) {
            $this->record($investigation, $anomaly, [
                'evidence_type' => InvestigationEvidence::TYPE_CALCULATION,
                'source'        => 'sales_transactions',
                'label'         => "Return rate (returns / gross sales, last 30 days)",
                'value_numeric' => $returnRate,
                'unit'          => '%',
                'direction'     => InvestigationEvidence::DIRECTION_SUPPORTS,
                'strength'      => InvestigationEvidence::STRENGTH_STRONG,
                'observed_at'   => now(),
            ]);
        }
    }

    // =========================================================================
    // INVENTORY EVIDENCE
    // =========================================================================

    private function collectInventoryEvidence(Investigation $investigation, Anomaly $anomaly): void
    {
        $tenantId = $investigation->tenant_id;

        // Current inventory level
        $invQuery = InventoryLevel::where('tenant_id', $tenantId)
            ->where('sku', $anomaly->sku);

        if ($anomaly->store_id) {
            $invQuery->where('store_id', $anomaly->store_id);
        }

        $level = $invQuery->latest('as_of_date')->first();

        if ($level) {
            // Persist as a snapshot for historical tracking
            InventorySnapshot::firstOrCreate(
                [
                    'tenant_id'     => $tenantId,
                    'sku'           => $anomaly->sku,
                    'store_id'      => $anomaly->store_id,
                    'snapshot_date' => now()->toDateString(),
                ],
                [
                    'product_id'    => $level->product_id ?? null,
                    'on_hand_qty'   => $level->on_hand_qty,
                    'reorder_point' => $level->reorder_point,
                    'unit_cost'     => null,
                    'source'        => 'system',
                ]
            );

            $this->record($investigation, $anomaly, [
                'evidence_type' => InvestigationEvidence::TYPE_SNAPSHOT,
                'source'        => 'inventory_levels',
                'label'         => "Current on-hand quantity",
                'value_numeric' => $level->on_hand_qty,
                'unit'          => 'units',
                'direction'     => InvestigationEvidence::DIRECTION_SUPPORTS,
                'strength'      => InvestigationEvidence::STRENGTH_STRONG,
                'observed_at'   => $level->as_of_date,
            ]);

            if ($level->reorder_point !== null) {
                $this->record($investigation, $anomaly, [
                    'evidence_type' => InvestigationEvidence::TYPE_THRESHOLD_BREACH,
                    'source'        => 'inventory_levels',
                    'label'         => "Reorder point",
                    'value_numeric' => $level->reorder_point,
                    'unit'          => 'units',
                    'direction'     => InvestigationEvidence::DIRECTION_NEUTRAL,
                    'strength'      => InvestigationEvidence::STRENGTH_STRONG,
                    'observed_at'   => $level->as_of_date,
                ]);

                // Cover ratio (days of stock at average daily sales)
                $avgDailySales = SalesTransaction::where('tenant_id', $tenantId)
                    ->where('sku', $anomaly->sku)
                    ->where('date', '>=', Carbon::today()->subDays(30)->format('Y-m-d'))
                    ->where('quantity', '>', 0)
                    ->selectRaw('SUM(quantity) / 30.0 as avg_daily')
                    ->value('avg_daily');

                if ($avgDailySales && $avgDailySales > 0) {
                    $coverDays = round($level->on_hand_qty / $avgDailySales, 1);
                    $this->record($investigation, $anomaly, [
                        'evidence_type' => InvestigationEvidence::TYPE_CALCULATION,
                        'source'        => 'inventory_levels + sales_transactions',
                        'label'         => "Days of cover at current sales rate",
                        'value_numeric' => $coverDays,
                        'unit'          => 'days',
                        'direction'     => $coverDays < 7
                            ? InvestigationEvidence::DIRECTION_SUPPORTS
                            : InvestigationEvidence::DIRECTION_CONTRADICTS,
                        'strength'      => InvestigationEvidence::STRENGTH_STRONG,
                        'observed_at'   => now(),
                    ]);
                }
            }
        }
    }

    // =========================================================================
    // PURCHASE ORDER EVIDENCE
    // =========================================================================

    private function collectPoEvidence(Investigation $investigation, Anomaly $anomaly): void
    {
        $tenantId = $investigation->tenant_id;

        $poQuery = PurchaseOrder::where('tenant_id', $tenantId);

        if ($anomaly->sku) {
            $poQuery->where('sku', $anomaly->sku);
        }

        $recentPos = $poQuery->latest('order_date')->limit(10)->get();

        if ($recentPos->isNotEmpty()) {
            $poSummary = $recentPos->map(fn ($po) => [
                'po_number'     => $po->po_number,
                'order_date'    => $po->order_date?->format('Y-m-d'),
                'expected_date' => $po->expected_date?->format('Y-m-d'),
                'received_date' => $po->received_date?->format('Y-m-d'),
                'qty_ordered'   => $po->qty_ordered,
                'qty_received'  => $po->qty_received,
                'unit_cost'     => $po->unit_cost,
            ])->toArray();

            $this->record($investigation, $anomaly, [
                'evidence_type' => InvestigationEvidence::TYPE_DATA_POINT,
                'source'        => 'purchase_orders',
                'label'         => "Recent purchase orders (last 10)",
                'value_json'    => $poSummary,
                'unit'          => null,
                'direction'     => InvestigationEvidence::DIRECTION_SUPPORTS,
                'strength'      => InvestigationEvidence::STRENGTH_MODERATE,
                'observed_at'   => now(),
            ]);

            // Average lead time from historical POs
            $avgLeadTime = $recentPos
                ->filter(fn ($po) => $po->order_date && $po->received_date)
                ->map(fn ($po) => (int) $po->order_date->diffInDays($po->received_date))
                ->average();

            if ($avgLeadTime !== null) {
                $this->record($investigation, $anomaly, [
                    'evidence_type' => InvestigationEvidence::TYPE_STAT,
                    'source'        => 'purchase_orders',
                    'label'         => "Average supplier lead time (recent POs)",
                    'value_numeric' => round($avgLeadTime, 1),
                    'unit'          => 'days',
                    'direction'     => InvestigationEvidence::DIRECTION_NEUTRAL,
                    'strength'      => InvestigationEvidence::STRENGTH_MODERATE,
                    'observed_at'   => now(),
                ]);
            }
        }
    }

    // =========================================================================
    // FINANCIAL EVIDENCE
    // =========================================================================

    private function collectFinancialEvidence(Investigation $investigation, Anomaly $anomaly): void
    {
        $tenantId = $investigation->tenant_id;
        $since    = Carbon::today()->subDays(30)->format('Y-m-d');

        // Recent price history for the SKU
        $prices = SalesTransaction::where('tenant_id', $tenantId)
            ->where('sku', $anomaly->sku)
            ->where('date', '>=', $since)
            ->whereNotNull('unit_price')
            ->where('unit_price', '>', 0)
            ->selectRaw("MIN(unit_price) as min_price, MAX(unit_price) as max_price, AVG(unit_price) as avg_price, COUNT(*) as tx_count")
            ->first();

        if ($prices && $prices->tx_count > 0) {
            $this->record($investigation, $anomaly, [
                'evidence_type' => InvestigationEvidence::TYPE_STAT,
                'source'        => 'sales_transactions',
                'label'         => "Price range last 30 days (min / avg / max)",
                'value_json'    => [
                    'min' => round((float)$prices->min_price, 4),
                    'avg' => round((float)$prices->avg_price, 4),
                    'max' => round((float)$prices->max_price, 4),
                    'tx_count' => (int)$prices->tx_count,
                ],
                'unit'          => '$',
                'direction'     => InvestigationEvidence::DIRECTION_SUPPORTS,
                'strength'      => InvestigationEvidence::STRENGTH_STRONG,
                'observed_at'   => now(),
            ]);
        }

        // Unit cost from product master
        $product = Product::where('tenant_id', $tenantId)->where('sku', $anomaly->sku)->first();
        if ($product && $product->unit_cost) {
            $this->record($investigation, $anomaly, [
                'evidence_type' => InvestigationEvidence::TYPE_STAT,
                'source'        => 'products',
                'label'         => "Unit cost (product master)",
                'value_numeric' => $product->unit_cost,
                'unit'          => '$',
                'direction'     => InvestigationEvidence::DIRECTION_NEUTRAL,
                'strength'      => InvestigationEvidence::STRENGTH_STRONG,
                'observed_at'   => now(),
            ]);

            // Margin at average price
            if ($prices && $prices->avg_price) {
                $margin = (((float)$prices->avg_price - $product->unit_cost) / (float)$prices->avg_price) * 100;
                $this->record($investigation, $anomaly, [
                    'evidence_type' => InvestigationEvidence::TYPE_CALCULATION,
                    'source'        => 'sales_transactions + products',
                    'label'         => "Gross margin at average sale price",
                    'value_numeric' => round($margin, 2),
                    'unit'          => '%',
                    'direction'     => $margin < 0
                        ? InvestigationEvidence::DIRECTION_SUPPORTS
                        : InvestigationEvidence::DIRECTION_CONTRADICTS,
                    'strength'      => InvestigationEvidence::STRENGTH_STRONG,
                    'observed_at'   => now(),
                ]);
            }
        }
    }

    // =========================================================================
    // DATA QUALITY EVIDENCE
    // =========================================================================

    private function collectDataQualityEvidence(Investigation $investigation, Anomaly $anomaly): void
    {
        $tenantId = $investigation->tenant_id;

        // Last successful ingestion run per data type
        $lastRuns = IngestionRun::where('tenant_id', $tenantId)
            ->where('status', IngestionRun::STATUS_COMPLETED)
            ->selectRaw('data_type, MAX(completed_at) as last_completed')
            ->groupBy('data_type')
            ->get()
            ->pluck('last_completed', 'data_type')
            ->toArray();

        if (!empty($lastRuns)) {
            $this->record($investigation, $anomaly, [
                'evidence_type' => InvestigationEvidence::TYPE_IMPORT_RUN,
                'source'        => 'ingestion_runs',
                'label'         => "Last successful import per data type",
                'value_json'    => $lastRuns,
                'unit'          => null,
                'direction'     => InvestigationEvidence::DIRECTION_SUPPORTS,
                'strength'      => InvestigationEvidence::STRENGTH_STRONG,
                'observed_at'   => now(),
            ]);
        }

        // SKU count in sales vs products (for sku_master_drift)
        if ($anomaly->rule_type === 'sku_master_drift') {
            $salesSkuCount   = SalesTransaction::where('tenant_id', $tenantId)->distinct('sku')->count('sku');
            $productSkuCount = Product::where('tenant_id', $tenantId)->count();

            $this->record($investigation, $anomaly, [
                'evidence_type' => InvestigationEvidence::TYPE_STAT,
                'source'        => 'sales_transactions + products',
                'label'         => "Distinct SKUs in sales vs product master",
                'value_json'    => ['in_sales' => $salesSkuCount, 'in_products' => $productSkuCount],
                'unit'          => 'SKUs',
                'direction'     => $salesSkuCount > $productSkuCount
                    ? InvestigationEvidence::DIRECTION_SUPPORTS
                    : InvestigationEvidence::DIRECTION_CONTRADICTS,
                'strength'      => InvestigationEvidence::STRENGTH_STRONG,
                'observed_at'   => now(),
            ]);
        }
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Persist one evidence row. Skips duplicates via firstOrCreate on
     * (investigation_id, anomaly_id, label) — same label = same fact.
     */
    private function record(Investigation $investigation, Anomaly $anomaly, array $attrs): void
    {
        InvestigationEvidence::firstOrCreate(
            [
                'investigation_id' => $investigation->id,
                'anomaly_id'       => $anomaly->id,
                'label'            => $attrs['label'],
            ],
            array_diff_key($attrs, ['label' => ''])
            + ['label' => $attrs['label']]
        );
    }
}
