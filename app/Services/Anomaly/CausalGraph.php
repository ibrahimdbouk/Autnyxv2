<?php

namespace App\Services\Anomaly;

/**
 * B8 — the retail causal graph.
 *
 * Domain knowledge, encoded once: which anomaly types plausibly CAUSE which
 * others, and by what shared key two such anomalies must be linked to count as a
 * real cause→effect pair (same SKU, same SKU+store, same store, or same
 * supplier). This is deterministic and auditable — no learned weights, no AI
 * guessing. The inference engine (RootCauseAnalysisService) walks a group of
 * co-occurring anomalies against these edges to assert a likely root cause.
 *
 * Reading an edge: [cause_rule, effect_rule, link_scope].
 */
final class CausalGraph
{
    // Link scopes.
    public const SCOPE_SKU       = 'sku';        // same SKU
    public const SCOPE_SKU_STORE = 'sku_store';  // same SKU AND same store
    public const SCOPE_STORE     = 'store';      // same store
    public const SCOPE_SUPPLIER  = 'supplier';   // same supplier

    /**
     * The causal edges. Each chain reflects a real retail mechanism:
     *  - supply failures cause stockouts, which cause lost sales;
     *  - cost spikes squeeze margin, which drives discounting;
     *  - unexplained shrink surfaces as phantom / negative inventory;
     *  - price and demand shocks cause stockouts / sales moves.
     */
    public const EDGES = [
        // Supply failure → stockout → lost sales
        ['supplier_fill_rate',        'stockout_risk',         self::SCOPE_SKU],
        ['supplier_fill_rate',        'safety_stock_breach',   self::SCOPE_SKU],
        ['po_late_receipt',           'stockout_risk',         self::SCOPE_SKU],
        ['po_overdue',                'stockout_risk',         self::SCOPE_SKU],
        ['supplier_lead_time_drift',  'stockout_risk',         self::SCOPE_SKU],
        ['receiving_discrepancy',     'stockout_risk',         self::SCOPE_SKU],
        ['stockout_risk',             'sales_drop',            self::SCOPE_SKU_STORE],
        ['stockout_risk',             'demand_forecast_break', self::SCOPE_SKU],
        ['safety_stock_breach',       'sales_drop',            self::SCOPE_SKU_STORE],

        // Cost → margin → discounting
        ['cost_spike',                'margin_erosion',        self::SCOPE_SKU],
        ['margin_erosion',            'discount_signal',       self::SCOPE_SKU],
        ['cost_spike',                'discount_signal',       self::SCOPE_SKU],

        // Shrink → data-integrity symptoms
        ['inventory_shrinkage',       'phantom_inventory',     self::SCOPE_SKU_STORE],
        ['inventory_shrinkage',       'negative_inventory',    self::SCOPE_SKU_STORE],
        ['cumulative_shrink',         'phantom_inventory',     self::SCOPE_SKU_STORE],

        // Price / demand shocks
        ['price_anomaly',             'sales_drop',            self::SCOPE_SKU],
        ['price_anomaly',             'sales_spike',           self::SCOPE_SKU],
        ['sales_spike',               'stockout_risk',         self::SCOPE_SKU_STORE],
        ['demand_forecast_break',     'stockout_risk',         self::SCOPE_SKU_STORE],
        ['return_rate_spike',         'sales_drop',            self::SCOPE_SKU],

        // Mix → store performance
        ['channel_mix_shift',         'store_outlier',         self::SCOPE_STORE],
    ];

    /** The link scope for a cause→effect pair, or null if no such edge exists. */
    public static function scopeFor(string $cause, string $effect): ?string
    {
        foreach (self::EDGES as [$c, $e, $scope]) {
            if ($c === $cause && $e === $effect) return $scope;
        }

        return null;
    }
}
