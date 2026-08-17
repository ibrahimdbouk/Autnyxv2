<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnomalySetting extends Model
{
    // All 10 rule definitions — single source of truth for labels, severity, defaults
    const RULES = [
        'sales_spike' => [
            'label'              => 'Sales Spike',
            'description'        => 'A SKU\'s sales significantly exceed its rolling average.',
            'severity'           => 'low',
            'default_thresholds' => ['pct' => 50, 'days' => 7],
        ],
        'sales_drop' => [
            'label'              => 'Sales Drop',
            'description'        => 'A SKU\'s sales significantly fall below its rolling average.',
            'severity'           => 'medium',
            'default_thresholds' => ['pct' => 30, 'days' => 7],
        ],
        'stockout_risk' => [
            'label'              => 'Stockout Risk',
            'description'        => 'On-hand quantity is at or below the reorder point.',
            'severity'           => 'high',
            'default_thresholds' => [],
        ],
        'dead_stock' => [
            'label'              => 'Dead Stock',
            'description'        => 'A SKU has inventory but no sales in the past N days.',
            'severity'           => 'low',
            'default_thresholds' => ['days' => 30],
        ],
        'po_overdue' => [
            'label'              => 'PO Overdue',
            'description'        => 'A purchase order\'s expected delivery date passed with no full receipt.',
            'severity'           => 'medium',
            'default_thresholds' => [],
        ],
        'price_anomaly' => [
            'label'              => 'Price Anomaly',
            'description'        => 'A SKU\'s recent sale price is outside its normal range.',
            'severity'           => 'low',
            'default_thresholds' => ['pct' => 25],
        ],
        'receiving_discrepancy' => [
            'label'              => 'Receiving Discrepancy',
            'description'        => 'A PO was closed with significantly less received than ordered.',
            'severity'           => 'medium',
            'default_thresholds' => ['pct' => 20],
        ],
        'margin_erosion' => [
            'label'              => 'Margin Erosion',
            'description'        => 'A SKU was sold below its unit cost.',
            'severity'           => 'high',
            'default_thresholds' => [],
        ],
        'store_outlier' => [
            'label'              => 'Store Outlier',
            'description'        => 'One location\'s sales for a SKU are far below other locations.',
            'severity'           => 'medium',
            'default_thresholds' => ['pct' => 50, 'days' => 7],
        ],
        'inventory_shrinkage' => [
            'label'              => 'Inventory Shrinkage',
            'description'        => 'On-hand quantity dropped more than sales can explain.',
            'severity'           => 'high',
            'default_thresholds' => ['pct' => 20],
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
     * Ensure all 10 rule rows exist for a tenant (idempotent).
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
        if (isset($thresholds['pct'])) $parts[] = "±{$thresholds['pct']}%";
        if (isset($thresholds['days'])) $parts[] = "{$thresholds['days']} days";

        return implode(', ', $parts);
    }
}
