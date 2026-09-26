<?php

namespace App\Support\Detection;

/**
 * WP4.4 (audit H21) — what kind of money an anomaly's figure is.
 *
 *   lost_revenue     sales (or margin) the business is losing now: stockouts,
 *                    drops, shortfalls against plan or forecast, pricing leaks.
 *   capital_at_cost  money tied up or written off at cost: overstock, phantom,
 *                    dead and slow stock, shrink, supply shortfalls.
 *   upside           demand above expectation — an opportunity, not a loss.
 *   data_quality     a data or process problem with no money figure of its own.
 *
 * Only lost_revenue counts toward "revenue at risk" (and toward recovery);
 * capital is reported beside it, never added to it.
 */
final class ValueModel
{
    public const LOST_REVENUE = 'lost_revenue';
    public const CAPITAL      = 'capital_at_cost';
    public const UPSIDE       = 'upside';
    public const DATA_QUALITY = 'data_quality';

    private const BY_RULE = [
        'sales_drop'                 => self::LOST_REVENUE,
        'stockout_risk'              => self::LOST_REVENUE,
        'safety_stock_breach'        => self::LOST_REVENUE,
        'store_outlier'              => self::LOST_REVENUE,
        'demand_erosion'             => self::LOST_REVENUE,
        'demand_seasonality_breach'  => self::LOST_REVENUE,
        'demand_forecast_break'      => self::LOST_REVENUE,
        'plan_variance'              => self::LOST_REVENUE,
        'cannibalization_signal'     => self::LOST_REVENUE,
        'channel_mix_shift'          => self::LOST_REVENUE,
        'return_rate_spike'          => self::LOST_REVENUE,
        'price_anomaly'              => self::LOST_REVENUE,
        'margin_erosion'             => self::LOST_REVENUE,
        'discount_signal'            => self::LOST_REVENUE,
        'cost_spike'                 => self::LOST_REVENUE,

        'overstock'                  => self::CAPITAL,
        'phantom_inventory'          => self::CAPITAL,
        'dead_stock'                 => self::CAPITAL,
        'slow_moving_capital'        => self::CAPITAL,
        'inventory_shrinkage'        => self::CAPITAL,
        'cumulative_shrink'          => self::CAPITAL,
        'expiry_risk'                => self::CAPITAL,   // W11: stock that will expire unsold, at cost
        'waste_rate'                 => self::CAPITAL,   // W11: stock written off, at cost
        'multi_location_imbalance'   => self::CAPITAL,
        'receiving_discrepancy'      => self::CAPITAL,
        'order_plan_variance'        => self::CAPITAL,
        'po_overdue'                 => self::CAPITAL,
        'po_late_receipt'            => self::CAPITAL,
        'supplier_fill_rate'         => self::CAPITAL,
        'supplier_lead_time_drift'   => self::CAPITAL,

        'sales_spike'                => self::UPSIDE,

        'negative_inventory'         => self::DATA_QUALITY,
        'reorder_point_staleness'    => self::DATA_QUALITY,
        'duplicate_transaction_ids'  => self::DATA_QUALITY,
        'sku_master_drift'           => self::DATA_QUALITY,
        'import_frequency_gap'       => self::DATA_QUALITY,
        'location_proliferation'     => self::DATA_QUALITY,
        'revenue_concentration_risk' => self::DATA_QUALITY,
    ];

    /** Context keys that carry the anomaly's money figure, in order of preference. */
    private const AMOUNT_KEYS = ['revenue_impact', 'inventory_value', 'goods_value', 'margin_lost', 'value_impact'];

    public static function type(string $ruleType, array $context = []): string
    {
        // W10: a tenant-defined rule says what its money is.
        if ($ruleType === 'custom_rule') {
            $v = $context['value_type'] ?? null;

            return in_array($v, [self::LOST_REVENUE, self::CAPITAL, self::UPSIDE, self::DATA_QUALITY], true) ? $v : self::DATA_QUALITY;
        }
        $type = self::BY_RULE[$ruleType] ?? self::DATA_QUALITY;

        // Demand/plan rules fire both ways: demand ABOVE expectation is upside.
        // (order_plan_variance "over" is over-ordering — capital, so unchanged.)
        if ($type === self::LOST_REVENUE && in_array($context['direction'] ?? null, ['above', 'gained'], true)) {
            return self::UPSIDE;
        }
        if ($ruleType === 'channel_mix_shift' && ($context['shift_pct'] ?? 0) > 0) {
            return self::UPSIDE;
        }

        return $type;
    }

    public static function amount(array $context): float
    {
        foreach (self::AMOUNT_KEYS as $k) {
            if (isset($context[$k]) && is_numeric($context[$k])) {
                return (float) $context[$k];
            }
        }

        return 0.0;
    }

    /** @return string[] */
    public static function rulesOfType(string $type): array
    {
        return array_keys(array_filter(self::BY_RULE, fn ($t) => $t === $type));
    }
}
