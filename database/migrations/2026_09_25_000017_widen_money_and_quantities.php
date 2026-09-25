<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WP6.5 (audit M23) — one money type, numeric(18,4), and one quantity type,
 * numeric(14,4), so amounts in VND / IDR / LBP (hundreds of millions per
 * line) never overflow and every table carries the same precision.
 *
 * Widening the precision at the same scale is a catalogue change in
 * PostgreSQL (no rewrite, a brief lock). A column whose SCALE changes (2 → 4)
 * rewrites its table: those are the small tables, plus anomalies and
 * sku_replenishment (tens of thousands of rows) — seconds. Each table is its
 * own statement (committed on its own, under the migration lock timeout), and
 * a column already at the target type is left alone, so a re-run is a no-op.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const MONEY = [
        'sales_transactions'    => ['unit_price', 'total_amount', 'discount', 'cost_amount'],
        'sales_returns'         => ['value'],
        'inventory_levels'      => ['inventory_value', 'unit_cost'],
        'inventory_snapshots'   => ['unit_cost'],
        'purchase_orders'       => ['unit_cost'],
        'products'              => ['unit_cost', 'selling_price'],
        'suppliers'             => ['min_order_value'],
        'anomalies'             => ['value_at_open'],
        'investigations'        => ['revenue_at_risk', 'capital_at_risk', 'observed_recovery', 'ai_revenue_estimate'],
        'investigation_outcomes'=> ['revenue_at_risk', 'observed_recovery', 'cost_to_resolve'],
        'outcome_measurements'  => ['recovery_amount', 'baseline_value', 'observed_value', 'expected_value', 'delta_value'],
        'decision_cases'        => ['expected_value', 'realized_value'],
        'sku_replenishment'     => ['unit_cost', 'order_value'],
        'platform_events'       => ['value'],
    ];

    private const QUANTITY = [
        'sales_transactions'  => ['quantity'],
        'sales_returns'       => ['quantity'],
        'inventory_levels'    => ['on_hand_qty', 'on_order_qty', 'reorder_point', 'safety_stock', 'allocated_qty', 'in_transit_qty'],
        'inventory_snapshots' => ['on_hand_qty', 'reorder_point'],
        'purchase_orders'     => ['qty_ordered', 'qty_received', 'open_qty'],
        'sku_replenishment'   => ['on_hand', 'order_up_to', 'reorder_point', 'safety_stock', 'suggested_order_qty'],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $tables = array_unique(array_merge(array_keys(self::MONEY), array_keys(self::QUANTITY)));
        foreach ($tables as $table) {
            $want = array_fill_keys(self::MONEY[$table] ?? [], [18, 4]) + array_fill_keys(self::QUANTITY[$table] ?? [], [14, 4]);
            $have = collect(DB::select(
                'SELECT column_name, numeric_precision AS p, numeric_scale AS s FROM information_schema.columns
                  WHERE table_schema = current_schema() AND table_name = ? AND data_type = ?', [$table, 'numeric']
            ))->keyBy('column_name');

            $alters = [];
            foreach ($want as $col => [$p, $s]) {
                $cur = $have->get($col);
                if ($cur === null) {
                    continue; // column not in this schema version
                }
                // Never narrow: a column already wider keeps its precision.
                $p = max($p, (int) $cur->p - (int) $cur->s + $s);
                if ((int) $cur->p === $p && (int) $cur->s === $s) {
                    continue;
                }
                $alters[] = "ALTER COLUMN {$col} TYPE numeric({$p},{$s})";
            }
            if ($alters !== []) {
                DB::statement("ALTER TABLE {$table} " . implode(', ', $alters));
                Log::info('[WP6.5] numeric columns widened', ['table' => $table, 'columns' => count($alters)]);
            }
        }
    }

    public function down(): void
    {
        // Widening is not reversed: narrowing could fail on (or truncate) real amounts.
    }
};
