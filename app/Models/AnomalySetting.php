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
            'description'        => 'A SKU\'s sales deviate significantly from the same 30-day window in the prior year.',
            'severity'           => 'medium',
            'tier'               => 'core',   // Requires: sales_transactions (>1 year of data to fire)
            'default_thresholds' => ['pct' => 40],
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
            'description'        => 'A location\'s share of total sales shifts significantly vs the prior period.',
            'severity'           => 'medium',
            'tier'               => 'core',   // Requires: sales_transactions.location
            'default_thresholds' => ['pct' => 25, 'days' => 30],
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
        if (isset($thresholds['pct']))       $parts[] = "±{$thresholds['pct']}%";
        if (isset($thresholds['days']))      $parts[] = "{$thresholds['days']} days";
        if (isset($thresholds['min_value'])) $parts[] = "min \${$thresholds['min_value']}";

        return implode(', ', $parts);
    }
}
