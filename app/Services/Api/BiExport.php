<?php

namespace App\Services\Api;

use App\Models\AnomalySetting;
use App\Services\Suppliers\SupplierScorecardService;
use App\Support\Detection\ValueModel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * W13 — flat tables for BI tools (Power BI, Excel, Tableau, Looker Studio).
 *
 * Every dataset is one flat table, tenant-scoped, with a stable id and an
 * updated_at, so a report can refresh incrementally (?since=) and relate the
 * tables on their ids (store_id → stores, sku → products, investigation_id →
 * investigations). No people's names or e-mail addresses: users appear as ids.
 *
 * Row tables page by id (?after=); computed tables (supplier scorecard, value
 * by month) are returned whole.
 */
class BiExport
{
    public const MAX_LIMIT     = 10000;
    public const DEFAULT_LIMIT = 5000;

    /** @return array<string, array{label:string, description:string, computed?:bool}> */
    public static function catalogue(): array
    {
        return [
            'findings'           => ['label' => 'Findings', 'description' => 'Every finding (anomaly): rule, severity, lifecycle, value, store, SKU, store feedback.'],
            'investigations'     => ['label' => 'Investigations', 'description' => 'Grouped findings with their root cause, status, money at risk and dates.'],
            'outcomes'           => ['label' => 'Outcomes', 'description' => 'Measured recovery and capital released per investigation, with the measurement window.'],
            'actions'            => ['label' => 'Actions', 'description' => 'Actions taken on investigations: type, status, due and completion dates.'],
            'cycle_counts'       => ['label' => 'Cycle counts', 'description' => 'Count lists and results: system vs counted quantity, variance and value.'],
            'sales_weekly'       => ['label' => 'Sales by week', 'description' => 'Units, revenue and transactions per store, SKU and week (Monday), in the tenant currency.'],
            'sales_monthly'      => ['label' => 'Sales by month', 'description' => 'Units, revenue and transactions per store, SKU and month, in the tenant currency.'],
            'stores'             => ['label' => 'Stores', 'description' => 'Store dimension: code, name, region, city, country, format.'],
            'products'           => ['label' => 'Products', 'description' => 'Product dimension: SKU, name, department, category, brand, cost and price.'],
            'supplier_scorecard' => ['label' => 'Supplier scorecard', 'description' => 'Fill rate, on-time, lead time, cost change, score and grade per supplier (last 90 days).', 'computed' => true],
            'value_by_month'     => ['label' => 'Value by month', 'description' => 'Per month: findings, money found, measured recovery and capital released, actions completed.', 'computed' => true],
        ];
    }

    public static function exists(string $dataset): bool
    {
        return array_key_exists($dataset, self::catalogue());
    }

    public static function computed(string $dataset): bool
    {
        return (bool) (self::catalogue()[$dataset]['computed'] ?? false);
    }

    /** @return array<int,string> the columns, in order */
    public function columns(string $dataset): array
    {
        return match ($dataset) {
            'findings' => ['id', 'rule_type', 'rule_label', 'severity', 'lifecycle_state', 'sku', 'store_id', 'investigation_id', 'value_type', 'value',
                'description', 'detected_at', 'first_seen_at', 'last_seen_at', 'cleared_at', 'resolved_at', 'dismissed_at', 'dismiss_reason',
                'feedback', 'feedback_at', 'feedback_via', 'updated_at'],
            'investigations' => ['id', 'title', 'status', 'priority', 'primary_sku', 'primary_store_id', 'root_cause_rule', 'root_cause_tier',
                'ai_confidence', 'revenue_at_risk', 'capital_at_risk', 'anomaly_count', 'assigned_team_id', 'assigned_user_id',
                'opened_at', 'resolved_at', 'closed_at', 'snoozed_until', 'updated_at'],
            'outcomes' => ['id', 'investigation_id', 'outcome_state', 'outcome_type', 'attribution_status', 'evidence_strength', 'revenue_at_risk',
                'measured_recovery', 'measured_capital', 'observed_recovery', 'cost_to_resolve', 'was_false_positive',
                'measurement_window_start', 'measurement_window_end', 'recorded_at', 'updated_at'],
            'actions' => ['id', 'investigation_id', 'anomaly_id', 'action_type', 'title', 'status', 'priority', 'assigned_to', 'assigned_team_id',
                'due_at', 'acknowledged_at', 'completed_at', 'cancelled_at', 'created_at', 'updated_at'],
            'cycle_counts' => ['id', 'store_id', 'sku', 'reason', 'status', 'rank', 'system_qty', 'counted_qty', 'variance_qty', 'unit_cost',
                'variance_value', 'value_at_risk', 'count_source', 'counted_by', 'counted_at', 'anomaly_id', 'investigation_id', 'created_at', 'updated_at'],
            'sales_weekly' => ['id', 'store_id', 'sku', 'week_start', 'units_sold', 'revenue', 'transaction_count', 'days_sold', 'updated_at'],
            'sales_monthly' => ['id', 'store_id', 'sku', 'month_start', 'units_sold', 'revenue', 'transaction_count', 'days_sold', 'updated_at'],
            'stores' => ['id', 'code', 'name', 'region', 'city', 'country', 'format', 'banner', 'status', 'currency', 'opened_on', 'sales_area_sqm', 'updated_at'],
            'products' => ['id', 'sku', 'name', 'department', 'category', 'subcategory', 'brand', 'supplier', 'unit_cost', 'selling_price',
                'units_per_case', 'status', 'updated_at'],
            'supplier_scorecard' => ['supplier_id', 'name', 'lines', 'pos', 'ordered_value', 'fill_rate', 'on_time', 'lead_days', 'prev_lead_days',
                'cost_change', 'overdue_lines', 'overdue_value', 'stockouts', 'lost_revenue', 'score', 'grade'],
            'value_by_month' => ['month', 'findings', 'investigations_opened', 'lost_revenue_found', 'capital_found', 'measured_recovery',
                'measured_capital', 'actions_completed'],
        };
    }

    /** The row query for a row dataset (tenant-scoped, since-filtered, id-ordered). */
    public function query(string $dataset, int $tenantId, ?Carbon $since = null): Builder
    {
        $cols = $this->columns($dataset);
        $q = match ($dataset) {
            'findings' => DB::table('anomalies')->where('tenant_id', $tenantId)
                ->select(array_merge(array_diff($cols, ['rule_label', 'value']), ['context'])),
            'investigations' => DB::table('investigations')->where('tenant_id', $tenantId)->select($cols),
            'outcomes' => DB::table('investigation_outcomes')->where('tenant_id', $tenantId)->select($cols),
            'actions' => DB::table('actions')
                ->whereIn('investigation_id', DB::table('investigations')->where('tenant_id', $tenantId)->select('id'))
                ->select($cols),
            'cycle_counts' => DB::table('cycle_counts')->where('tenant_id', $tenantId)->select($cols),
            'sales_weekly' => DB::table('sales_weekly')->where('tenant_id', $tenantId)->select($cols),
            'sales_monthly' => DB::table('sales_monthly')->where('tenant_id', $tenantId)->select($cols),
            'stores' => DB::table('stores')->where('tenant_id', $tenantId)->select($cols),
            'products' => DB::table('products')->where('tenant_id', $tenantId)->select($cols),
        };

        return $q->when($since, fn ($q) => $q->where('updated_at', '>=', $since))->orderBy('id');
    }

    /** One stored row → the exported row (columns in order, dates ISO). */
    public function map(string $dataset, object $row): array
    {
        $r = (array) $row;
        if ($dataset === 'findings') {
            $ctx = is_string($r['context'] ?? null) ? (array) json_decode($r['context'], true) : (array) ($r['context'] ?? []);
            $r['rule_label'] = AnomalySetting::RULES[$r['rule_type']]['label'] ?? $r['rule_type'];
            $r['value'] = round(ValueModel::amount($ctx), 2);
            $r['value_type'] ??= ValueModel::type((string) $r['rule_type'], $ctx);
        }
        $out = [];
        foreach ($this->columns($dataset) as $c) {
            $v = $r[$c] ?? null;
            if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $v)) {
                $v = Carbon::parse($v)->toIso8601ZuluString();
            }
            $out[$c] = $v;
        }

        return $out;
    }

    /** @return array<int, array<string,mixed>> a computed dataset, whole */
    public function computedRows(string $dataset, int $tenantId): array
    {
        if ($dataset === 'supplier_scorecard') {
            $cols = $this->columns($dataset);

            return array_map(fn ($r) => array_intersect_key($r, array_flip($cols)), app(SupplierScorecardService::class)->scorecard($tenantId));
        }

        // value_by_month: the last 24 months.
        $from = now()->startOfMonth()->subMonths(23);
        $by = fn (string $sql, array $bind) => collect(DB::select($sql, $bind))->keyBy('m');
        $findings = $by("SELECT to_char(date_trunc('month', detected_at), 'YYYY-MM') AS m, COUNT(*) AS n FROM anomalies
            WHERE tenant_id = ? AND detected_at >= ? AND COALESCE(dismiss_reason, '') <> 'superseded' GROUP BY 1", [$tenantId, $from]);
        $inv = $by("SELECT to_char(date_trunc('month', opened_at), 'YYYY-MM') AS m, COUNT(*) AS n, SUM(revenue_at_risk) AS rev, SUM(capital_at_risk) AS cap
            FROM investigations WHERE tenant_id = ? AND opened_at >= ? GROUP BY 1", [$tenantId, $from]);
        $out = $by("SELECT to_char(date_trunc('month', recorded_at), 'YYYY-MM') AS m, SUM(measured_recovery) AS rec, SUM(measured_capital) AS cap
            FROM investigation_outcomes WHERE tenant_id = ? AND recorded_at >= ? GROUP BY 1", [$tenantId, $from]);
        $act = $by("SELECT to_char(date_trunc('month', a.completed_at), 'YYYY-MM') AS m, COUNT(*) AS n FROM actions a
            JOIN investigations i ON i.id = a.investigation_id WHERE i.tenant_id = ? AND a.completed_at >= ? GROUP BY 1", [$tenantId, $from]);

        $rows = [];
        for ($m = $from->copy(); $m->lte(now()); $m->addMonth()) {
            $k = $m->format('Y-m');
            $rows[] = [
                'month'                 => $k,
                'findings'              => (int) ($findings[$k]->n ?? 0),
                'investigations_opened' => (int) ($inv[$k]->n ?? 0),
                'lost_revenue_found'    => round((float) ($inv[$k]->rev ?? 0), 2),
                'capital_found'         => round((float) ($inv[$k]->cap ?? 0), 2),
                'measured_recovery'     => round((float) ($out[$k]->rec ?? 0), 2),
                'measured_capital'      => round((float) ($out[$k]->cap ?? 0), 2),
                'actions_completed'     => (int) ($act[$k]->n ?? 0),
            ];
        }

        return $rows;
    }
}
