<?php

namespace App\Services\Anomaly;

use App\Models\AnomalySetting;
use App\Models\SkuProfile;
use Illuminate\Support\Facades\DB;

/**
 * B3 — Smart defaults.
 *
 * A new tenant should start calibrated, not blank. Hand-tuning "min value =
 * $1,000" is meaningless until you know the tenant's price level: $1,000 is
 * one line for a wholesaler and a hundred for a corner grocer. This service
 * derives per-rule recommended materiality floors from the tenant's OWN
 * catalogue economics (price and cost distribution) and reports how the
 * catalogue's demand-segment mix should shape which rules carry the load.
 *
 * It only recommends the money floors it can defend from data (min_value /
 * min_revenue). It deliberately does NOT invent statistical thresholds
 * (deviation %, lookback) — those need labelled outcomes (B2), not guesses —
 * so those keep their built-in defaults.
 */
class ThresholdRecommenderService
{
    /** Rules whose noise is gated by a tied-up-value floor. */
    private const VALUE_FLOOR_RULES = [
        'slow_moving_capital', 'overstock', 'phantom_inventory',
        'receiving_discrepancy', 'cumulative_shrink', 'supplier_fill_rate',
    ];

    /** Rules whose noise is gated by an estimated-revenue-impact floor. */
    private const REVENUE_FLOOR_RULES = [
        'sales_spike', 'sales_drop', 'stockout_risk', 'cannibalization_signal',
        'demand_erosion', 'demand_seasonality_breach', 'demand_forecast_break',
    ];

    /**
     * @return array{
     *   economics: array{median_price: float, median_cost: float, products: int},
     *   segments: array<string,int>,
     *   intermittent_share: float,
     *   rules: array<string, array{key: string, recommended: int|float, rationale: string}>,
     *   notes: string[]
     * }
     */
    public function recommendForTenant(int $tenantId): array
    {
        $medianPrice = $this->median($tenantId, 'selling_price');
        $medianCost  = $this->median($tenantId, 'unit_cost');
        // Fall back across the two if one side of the master is empty.
        $medianPrice = $medianPrice ?: $medianCost;
        $medianCost  = $medianCost ?: $medianPrice;
        $products    = (int) DB::table('products')->where('tenant_id', $tenantId)->count();

        // Revenue floor ≈ a few units of a median-priced item (trims the trivial
        // tail while keeping staples); value floor ≈ a meaningful tied-up qty of
        // a median-cost item. Both nudged to clean numbers with a sane minimum.
        $revenueFloor = $this->niceRound(max(100.0, $medianPrice * 5));
        $valueFloor   = $this->niceRound(max(250.0, $medianCost * 20));

        $segments = DB::table('sku_profiles')->where('tenant_id', $tenantId)
            ->selectRaw('segment, COUNT(*) c')->groupBy('segment')
            ->pluck('c', 'segment')->map(fn ($c) => (int) $c)->toArray();
        $totalProfiles = array_sum($segments);
        $intermittent  = ($segments[SkuProfile::SEG_INTERMITTENT] ?? 0) + ($segments[SkuProfile::SEG_LUMPY] ?? 0);
        $intermittentShare = $totalProfiles > 0 ? $intermittent / $totalProfiles : 0.0;

        $rules = [];
        foreach (self::REVENUE_FLOOR_RULES as $r) {
            $rules[$r] = [
                'key'         => 'min_revenue',
                'recommended' => $revenueFloor,
                'rationale'   => "≈ 5 units of a median-priced item (" . $this->m($medianPrice) . ").",
            ];
        }
        foreach (self::VALUE_FLOOR_RULES as $r) {
            $rules[$r] = [
                'key'         => 'min_value',
                'recommended' => $valueFloor,
                'rationale'   => "≈ 20 units at median unit cost (" . $this->m($medianCost) . ").",
            ];
        }

        $notes = [];
        if ($totalProfiles === 0) {
            $notes[] = 'No SKU profiles yet — run sku:profile so recommendations can use the demand-segment mix.';
        } else {
            $notes[] = sprintf(
                '%s of the catalogue is intermittent/lumpy — demand is best watched by the best-fit forecast rule (demand_forecast_break); fixed-%% spike/drop rules are gated off those items automatically.',
                number_format($intermittentShare * 100, 0) . '%'
            );
        }

        return [
            'economics'          => ['median_price' => $medianPrice, 'median_cost' => $medianCost, 'products' => $products],
            'segments'           => $segments,
            'intermittent_share' => $intermittentShare,
            'rules'              => $rules,
            'notes'              => $notes,
        ];
    }

    /**
     * Recommended thresholds for a single rule, merged onto its current effective
     * thresholds (so applying keeps unrelated keys). Returns null if this rule
     * has no data-derived recommendation.
     */
    public function recommendedThresholdsFor(AnomalySetting $setting, ?array $cached = null): ?array
    {
        $rec = $cached ?? $this->recommendForTenant($setting->tenant_id);
        $spec = $rec['rules'][$setting->rule_type] ?? null;
        if ($spec === null) return null;

        return array_merge($setting->getEffectiveThresholds(), [$spec['key'] => $spec['recommended']]);
    }

    private function median(int $tenantId, string $column): float
    {
        // PERCENTILE_CONT for a true median over positive values.
        $v = DB::table('products')
            ->where('tenant_id', $tenantId)
            ->where($column, '>', 0)
            ->selectRaw("PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY {$column}) AS m")
            ->value('m');

        return (float) ($v ?? 0);
    }

    /** Round to a clean, human threshold: 137 → 150, 2,340 → 2,500, 18 → 20. */
    private function niceRound(float $v): float
    {
        if ($v <= 0) return 0;
        $mag  = 10 ** floor(log10($v));
        $step = $mag / 2;                    // half-decade steps: 50, 100, 500, 1000…

        return (float) (ceil($v / $step) * $step);
    }

    private function m(float $v): string
    {
        return number_format($v, 2);
    }
}
