<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SkuProfile extends Model
{
    // Demand segments (Syntetos–Boylan quadrants + lifecycle overlays).
    const SEG_SMOOTH       = 'smooth';       // frequent, steady demand
    const SEG_ERRATIC      = 'erratic';      // frequent but variable size
    const SEG_INTERMITTENT = 'intermittent'; // infrequent, steady size
    const SEG_LUMPY        = 'lumpy';        // infrequent AND variable
    const SEG_DEAD         = 'dead';         // stock but ~no sales
    const SEG_NEW          = 'new';          // too little history to classify
    const SEG_UNKNOWN      = 'unknown';

    // Forecast model that best fits each segment.
    const MODEL_MOVING_AVERAGE = 'moving_average';
    const MODEL_SES            = 'ses';
    const MODEL_CROSTON        = 'croston';
    const MODEL_SBA            = 'sba';
    const MODEL_HOLT_WINTERS   = 'holt_winters';
    const MODEL_NONE           = 'none';

    protected $fillable = [
        'tenant_id', 'sku', 'store_id',
        'segment', 'volume_tier', 'lifecycle', 'chosen_model',
        'window_days', 'selling_days', 'total_units', 'total_revenue',
        'mean_nonzero', 'adi', 'cv2', 'trend_slope', 'trend_r2',
        'has_inventory', 'features', 'computed_at',
    ];

    protected $casts = [
        'total_units'   => 'float',
        'total_revenue' => 'float',
        'mean_nonzero'  => 'float',
        'adi'           => 'float',
        'cv2'           => 'float',
        'trend_slope'   => 'float',
        'trend_r2'      => 'float',
        'has_inventory' => 'boolean',
        'features'      => 'array',
        'computed_at'   => 'datetime',
    ];

    /**
     * Which rule types are relevant for this profile's segment. Phase 3 will use
     * this to gate detection; defined here so the mapping lives with the model.
     * A rule not listed for a segment is noise for that item.
     */
    public function appliesToRule(string $ruleType): bool
    {
        return in_array($ruleType, self::rulesForSegment($this->segment), true)
            || in_array($ruleType, self::ALWAYS_ON, true);
    }

    /** Rules that run regardless of segment (data-integrity / supply / financial / quality). */
    const ALWAYS_ON = [
        'negative_inventory', 'inventory_shrinkage', 'cumulative_shrink',
        'po_overdue', 'po_late_receipt', 'receiving_discrepancy', 'supplier_fill_rate',
        'supplier_lead_time_drift', 'cost_spike', 'margin_erosion', 'discount_signal',
        'price_anomaly', 'return_rate_spike', 'duplicate_transaction_ids', 'sku_master_drift',
        'import_frequency_gap', 'location_proliferation', 'revenue_concentration_risk',
        'channel_mix_shift',
    ];

    /** Whether a rule is relevant for a given segment (used by detection gating). */
    public static function segmentAllowsRule(string $segment, string $ruleType): bool
    {
        return in_array($ruleType, self::ALWAYS_ON, true)
            || in_array($ruleType, self::rulesForSegment($segment), true);
    }

    public static function rulesForSegment(string $segment): array
    {
        return match ($segment) {
            self::SEG_SMOOTH => [
                'sales_spike', 'sales_drop', 'demand_erosion', 'demand_seasonality_breach',
                'stockout_risk', 'overstock', 'store_outlier', 'cannibalization_signal',
                'return_rate_spike', 'channel_mix_shift',
            ],
            self::SEG_ERRATIC => [
                'sales_drop', 'demand_erosion', 'stockout_risk', 'overstock',
                'return_rate_spike', 'store_outlier',
            ],
            // Intermittent/lumpy: spike/drop are meaningless on bursty series;
            // lean on stockout/phantom/overstock + (future) Croston forecast.
            self::SEG_INTERMITTENT, self::SEG_LUMPY => [
                'stockout_risk', 'phantom_inventory', 'overstock', 'dead_stock',
            ],
            self::SEG_DEAD => [
                'dead_stock', 'phantom_inventory', 'slow_moving_capital', 'overstock',
            ],
            self::SEG_NEW => [
                'stockout_risk', 'phantom_inventory',
            ],
            default => [
                // Unknown → fall back to the broad demand+inventory set.
                'sales_spike', 'sales_drop', 'demand_erosion', 'stockout_risk',
                'overstock', 'phantom_inventory', 'dead_stock', 'store_outlier',
            ],
        };
    }
}
