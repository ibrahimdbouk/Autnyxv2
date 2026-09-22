<?php

namespace App\Services\DataQuality;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 6 — dry-run a cleansing rule against real data before activating it: sample
 * distinct current values for the field, apply the transform, and report how many
 * would change with before/after examples. Read-only. See claude/data-quality-firewall.md.
 */
class CleansingDryRunService
{
    private const TABLE = [
        'sales_transactions' => 'sales_transactions',
        'inventory_levels'   => 'inventory_levels',
        'products'           => 'products',
        'purchase_orders'    => 'purchase_orders',
        'returns'            => 'sales_returns',
        'stores'             => 'stores',
        'suppliers'          => 'suppliers',
        'users'              => 'users',
    ];

    /** @return array{supported:bool, checked:int, changed:int, samples:array<int,array{before:string,after:string}>} */
    public function preview(int $tenantId, string $dataType, string $field, string $ruleType, array $params = [], int $sample = 300): array
    {
        $empty = ['supported' => false, 'checked' => 0, 'changed' => 0, 'samples' => []];

        $table = self::TABLE[$dataType] ?? null;
        if ($table === null || ! Schema::hasColumn($table, $field)) {
            return $empty;
        }

        $values = DB::table($table)
            ->where('tenant_id', $tenantId)
            ->whereNotNull($field)
            ->distinct()
            ->limit($sample)
            ->pluck($field)
            ->all();

        $engine  = app(CleansingEngine::class);
        $checked = 0;
        $changed = 0;
        $samples = [];

        foreach ($values as $v) {
            $before = (string) $v;
            $after  = $engine->applyRuleType($before, $ruleType, $params);
            $checked++;
            if ($before !== $after) {
                $changed++;
                if (count($samples) < 8) {
                    $samples[] = ['before' => $before, 'after' => $after];
                }
            }
        }

        return ['supported' => true, 'checked' => $checked, 'changed' => $changed, 'samples' => $samples];
    }
}
