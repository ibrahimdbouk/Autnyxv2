<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnomalySetting extends Model
{
    // All rule definitions — single source of truth for labels, severity, defaults
    const RULES = [

        // ── Demand & Sales ───────────────────────────────────────────────────
        'sales_spike' => [
            'label'              => 'Sales Spike',
            'description'        => 'A SKU\'s sales significantly exceed its rolling average.',
            'severity'           => 'low',
            'tier'               => 'core',   // Requires: sales_transactions
            'default_thresholds' => ['pct' => 50, 'days' => 7],
        ],
        'sales_drop' => [
            'label'              => 'Sales Drop',
            'description'        => 'A SKU\'s sales significantly fall below its rolling average.',
            'severity'           => 'medium',
            'tier'               => 'core',   // Requires: sales_transactions
            'default_thresholds' => ['pct' => 30, 'days' => 7],
        ],
        'demand_seasonality_breach' => [
            'label'              => 'Demand Seasonality Breach',
            'description'        => 'A SKU\'s demand departs from what the calendar predicts — year-over-year when a year of data exists, otherwise a seasonal (day-of-week) adjusted expectation.',
            'severity'           => 'medium',
            'tier'               => 'core',   // Requires: sales_transactions / sales_daily
            'default_thresholds' => ['pct' => 40, 'min_revenue' => 1000],
        ],
        'cannibalization_signal' => [
            'label'              => 'Cannibalization Signal',
            'description'        => 'Within the same category, one SKU\'s sales rise while a sibling\'s fall.',
            'severity'           => 'medium',
            'tier'               => 'core',   // Requires: sales_transactions + products.category
            'default_thresholds' => ['pct' => 30, 'days' => 30],
        ],
        'return_rate_spike' => [
            'label'              => 'Return Rate Spike',
            'description'        => 'A SKU\'s return volume (negative quantities) exceeds a set percentage of its gross sales.',
            'severity'           => 'medium',
            'tier'               => 'core',   // Requires: sales_transactions with negative quantities for returns
            'default_thresholds' => ['pct' => 15, 'days' => 30],
        ],
        'channel_mix_shift' => [
            'label'              => 'Channel Mix Shift',
            'description'        => 'A location\'s share of total sales shifts significantly (relative change) vs the prior period.',
            'severity'           => 'medium',
            'tier'               => 'core',   // Requires: sales_transactions.location
            'default_thresholds' => ['pct' => 25, 'days' => 30, 'min_units' => 200],
        ],
        'demand_forecast_break' => [
            'label'              => 'Demand vs Best-Fit Forecast',
            'description'        => 'Recent demand departs from the SKU\'s own best-fit forecast (Croston/SBA for intermittent demand, seasonally adjusted), outside a per-SKU tolerance band. The demand detector for intermittent/lumpy items, where fixed-% spike/drop rules do not fit.',
            'severity'           => 'medium',
            'tier'               => 'core',   // Requires: sales_daily + sku_profiles
            'default_thresholds' => ['pct' => 100, 'days' => 7, 'window' => 90, 'alpha' => 0.2, 'min_occasions' => 3, 'min_revenue' => 500],
        ],
        'demand_erosion' => [
            'label'              => 'Slow Demand Erosion',
            'description'        => 'A SKU\'s demand is on a sustained downward trend (a gradual slide, not a sharp break) — the kind of erosion that recent-vs-window rules miss because both windows fall together.',
            'severity'           => 'medium',
            'tier'               => 'core',   // Requires: sales_daily
            'default_thresholds' => ['days' => 90, 'pct' => 40, 'min_units' => 20, 'min_r2' => 0.3, 'min_revenue' => 500],
        ],

        // ── Inventory & Supply ───────────────────────────────────────────────
        'stockout_risk' => [
            'label'              => 'Stockout Risk',
            'description'        => 'On-hand quantity is at or below the reorder point.',
            'severity'           => 'high',
            'tier'               => 'core',   // Requires: inventory_levels
            'default_thresholds' => [],
        ],
        'safety_stock_breach' => [
            'label'              => 'Safety Stock Breach',
            'description'        => 'On-hand quantity falls below 50% of the reorder point — critically low.',
            'severity'           => 'high',
            'tier'               => 'core',   // Requires: inventory_levels
            'default_thresholds' => [],
        ],
        'dead_stock' => [
            'label'              => 'Dead Stock',
            'description'        => 'A SKU has inventory but no sales in the past N days.',
            'severity'           => 'low',
            'tier'               => 'core',   // Requires: inventory_levels + sales_transactions
            'default_thresholds' => ['days' => 30],
        ],
        'phantom_inventory' => [
            'label'              => 'Phantom Inventory',
            'description'        => 'A SKU has inventory recorded but has never generated a sales transaction.',
            'severity'           => 'medium',
            'tier'               => 'core',   // Requires: inventory_levels + sales_transactions
            'default_thresholds' => [],
        ],
        'negative_inventory' => [
            'label'              => 'Negative Inventory',
            'description'        => 'A SKU\'s latest on-hand quantity is below zero — a data-integrity error that corrupts every downstream metric.',
            'severity'           => 'high',
            'tier'               => 'core',   // Requires: inventory_levels
            'default_thresholds' => [],
        ],
        'overstock' => [
            'label'              => 'Overstock',
            'description'        => 'A SKU holds far more stock than its recent demand can clear (days of cover beyond the threshold) — working capital tied up.',
            'severity'           => 'low',
            'tier'               => 'core',   // Requires: inventory_levels + sales_daily + products.unit_cost
            'default_thresholds' => ['days_cover' => 120, 'lookback_days' => 30, 'min_value' => 1000],
        ],
        'cumulative_shrink' => [
            'label'              => 'Concealed Shrink Pattern',
            'description'        => 'Unexplained inventory loss accumulated across the FULL snapshot history of a (SKU, location) — repeated small declines that a latest-two-snapshot check hides because stock is restocked between them.',
            'severity'           => 'high',
            'tier'               => 'core',   // Requires: multiple inventory_levels snapshots + sales_daily + products.unit_cost
            'default_thresholds' => ['min_value' => 1000, 'min_intervals' => 3],
        ],
        'multi_location_imbalance' => [
            'label'              => 'Multi-Location Imbalance',
            'description'        => 'The same SKU is overstocked in one location while at or below reorder point in another.',
            'severity'           => 'medium',
            'tier'               => 'core',   // Requires: inventory_levels with location
            'default_thresholds' => [],
        ],
        'reorder_point_staleness' => [
            'label'              => 'Reorder Point Staleness',
            'description'        => 'An inventory snapshot\'s reorder point hasn\'t been refreshed despite ongoing sales activity.',
            'severity'           => 'low',
            'tier'               => 'core',   // Requires: inventory_levels.as_of_date + sales_transactions
            'default_thresholds' => ['days' => 90],
        ],
        'inventory_shrinkage' => [
            'label'              => 'Inventory Shrinkage',
            'description'        => 'On-hand quantity dropped more than sales can explain between two snapshots.',
            'severity'           => 'high',
            'tier'               => 'core',   // Requires: multiple inventory_levels snapshots per SKU+location over time
            'default_thresholds' => ['pct' => 20],
        ],

        // ── Purchase Orders ──────────────────────────────────────────────────
        'po_overdue' => [
            'label'              => 'PO Overdue',
            'description'        => 'A purchase order\'s expected delivery date passed with no full receipt.',
            'severity'           => 'medium',
            'tier'               => 'core',   // Requires: purchase_orders
            'default_thresholds' => [],
        ],
        'receiving_discrepancy' => [
            'label'              => 'Receiving Discrepancy',
            'description'        => 'A PO was closed with significantly less received than ordered.',
            'severity'           => 'medium',
            'tier'               => 'core',   // Requires: purchase_orders
            'default_thresholds' => ['pct' => 20],
        ],
        'po_late_receipt' => [
            'label'              => 'Late PO Receipt',
            'description'        => 'A purchase order was received, but materially later than its expected date — a supplier service-level failure invisible to the overdue rule once the goods finally arrive.',
            'severity'           => 'medium',
            'tier'               => 'core',   // Requires: purchase_orders with expected_date + received_date
            'default_thresholds' => ['days' => 7],
        ],
        'supplier_fill_rate' => [
            'label'              => 'Chronic Supplier Under-Fill',
            'description'        => 'A supplier is persistently short on a SKU across many POs — each shortfall too small to trip the per-PO receiving check, but the repeated pattern is a real service-level failure.',
            'severity'           => 'medium',
            'tier'               => 'core',   // Requires: purchase_orders with qty_ordered + qty_received
            'default_thresholds' => ['pct' => 80, 'min_pos' => 3, 'min_value' => 500],
        ],
        'supplier_lead_time_drift' => [
            'label'              => 'Supplier Lead Time Drift',
            'description'        => 'A supplier\'s average delivery time is growing compared to their prior-period baseline.',
            'severity'           => 'medium',
            'tier'               => 'core',   // Requires: purchase_orders with order_date + received_date
            'default_thresholds' => ['pct' => 30],
        ],
        'cost_spike' => [
            'label'              => 'Cost Spike',
            'description'        => 'A purchase order\'s unit cost for a SKU significantly exceeds that SKU\'s historical average.',
            'severity'           => 'high',
            'tier'               => 'core',   // Requires: purchase_orders.unit_cost
            'default_thresholds' => ['pct' => 25],
        ],

        // ── Financial ────────────────────────────────────────────────────────
        'price_anomaly' => [
            'label'              => 'Price Anomaly',
            'description'        => 'A SKU\'s recent sale price is outside its normal range.',
            'severity'           => 'low',
            'tier'               => 'core',   // Requires: sales_transactions.unit_price
            'default_thresholds' => ['pct' => 25],
        ],
        'margin_erosion' => [
            'label'              => 'Margin Erosion',
            'description'        => 'A SKU was sold below its unit cost in recent transactions.',
            'severity'           => 'high',
            'tier'               => 'core',   // Requires: sales_transactions.unit_price + products.unit_cost
            'default_thresholds' => [],
        ],
        'discount_signal' => [
            'label'              => 'Discount Signal',
            'description'        => 'A product\'s list selling price is configured below its unit cost in the product master.',
            'severity'           => 'medium',
            'tier'               => 'core',   // Requires: products.selling_price + products.unit_cost
            'default_thresholds' => [],
        ],
        'revenue_concentration_risk' => [
            'label'              => 'Revenue Concentration Risk',
            'description'        => 'A small number of SKUs account for an outsized share of total revenue.',
            'severity'           => 'medium',
            'tier'               => 'core',   // Requires: sales_transactions.total_amount
            'default_thresholds' => ['pct' => 80, 'days' => 90],
        ],
        'slow_moving_capital' => [
            'label'              => 'Slow-Moving Capital',
            'description'        => 'High-value inventory (qty × unit cost) has had no sales — capital is tied up.',
            'severity'           => 'medium',
            'tier'               => 'core',   // Requires: inventory_levels + products.unit_cost + sales_transactions
            'default_thresholds' => ['days' => 60, 'min_value' => 1000],
        ],

        // ── Store Performance ─────────────────────────────────────────────────
        'store_outlier' => [
            'label'              => 'Store Outlier',
            'description'        => 'One location\'s sales for a SKU are far below other locations.',
            'severity'           => 'medium',
            'tier'               => 'core',   // Requires: sales_transactions.location
            'default_thresholds' => ['pct' => 50, 'days' => 7],
        ],

        // ── Operational / Data Quality ────────────────────────────────────────
        'import_frequency_gap' => [
            'label'              => 'Import Frequency Gap',
            'description'        => 'No completed data import has been received within the expected cadence window.',
            'severity'           => 'medium',
            'tier'               => 'core',   // Requires: imports table
            'default_thresholds' => ['days' => 7],
        ],
        'duplicate_transaction_ids' => [
            'label'              => 'Duplicate Transaction IDs',
            'description'        => 'The same transaction ID appears more than once in sales data — likely a duplicate import.',
            'severity'           => 'high',
            'tier'               => 'core',   // Requires: sales_transactions.transaction_id
            'default_thresholds' => [],
        ],
        'sku_master_drift' => [
            'label'              => 'SKU Master Drift',
            'description'        => 'SKUs are appearing in sales or inventory data with no matching product master record.',
            'severity'           => 'low',
            'tier'               => 'core',   // Requires: sales_transactions + inventory_levels + products
            'default_thresholds' => [],
        ],
        'location_proliferation' => [
            'label'              => 'Location Proliferation',
            'description'        => 'New store locations were auto-created from imports with no enrichment data — possible duplicates or typos.',
            'severity'           => 'low',
            'tier'               => 'core',   // Requires: stores table
            'default_thresholds' => ['days' => 7],
        ],
    ];

    protected $fillable = ['tenant_id', 'rule_type', 'enabled', 'thresholds'];

    protected $casts = [
        'enabled'    => 'boolean',
        'thresholds' => 'array',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Ensure all rule rows exist for a tenant (idempotent).
     */
    public static function seedForTenant(int $tenantId): void
    {
        foreach (self::RULES as $ruleType => $config) {
            self::firstOrCreate(
                ['tenant_id' => $tenantId, 'rule_type' => $ruleType],
                [
                    'enabled'    => true,
                    'thresholds' => !empty($config['default_thresholds']) ? $config['default_thresholds'] : null,
                ]
            );
        }
    }

    public function getRuleLabel(): string
    {
        return self::RULES[$this->rule_type]['label'] ?? $this->rule_type;
    }

    public function getRuleDescription(): string
    {
        return self::RULES[$this->rule_type]['description'] ?? '';
    }

    public function getDefaultSeverity(): string
    {
        return self::RULES[$this->rule_type]['severity'] ?? 'low';
    }

    public function getEffectiveThresholds(): array
    {
        return array_merge(
            self::RULES[$this->rule_type]['default_thresholds'] ?? [],
            $this->thresholds ?? []
        );
    }

    public function getThresholdsSummary(): string
    {
        $thresholds = $this->getEffectiveThresholds();
        if (empty($thresholds)) return '—';

        $parts = [];
        if (isset($thresholds['pct']))         $parts[] = "±{$thresholds['pct']}%";
        if (isset($thresholds['days']))        $parts[] = "{$thresholds['days']} days";
        if (isset($thresholds['days_cover']))  $parts[] = "{$thresholds['days_cover']}d cover";
        if (isset($thresholds['min_value']))   $parts[] = "min \${$thresholds['min_value']}";
        if (isset($thresholds['min_revenue'])) $parts[] = "floor \${$thresholds['min_revenue']}";

        return implode(', ', $parts);
    }
}
