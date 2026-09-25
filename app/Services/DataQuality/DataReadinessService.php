<?php

namespace App\Services\DataQuality;

use App\Models\ImportQuality;
use Illuminate\Support\Facades\DB;

/**
 * The detection-readiness contract. Detection reads THIS, not the firewall internals:
 * per dataset (data_type) it answers READY vs BLOCKED from that dataset's most recent
 * batch decision, and maps that to the exact detection rules that depend on it — so a
 * blocked Inventory feed disables inventory rules while healthy Sales rules keep running.
 * Root-Cause / detection logic is never rewritten; the runner just skips blocked rules.
 * See claude/data-quality-firewall.md.
 */
class DataReadinessService
{
    /** Detection rule → the dataset it consumes. Rules absent here are always ready. */
    public const RULE_DATASET = [
        // Inventory
        'stockout_risk'            => 'inventory_levels',
        'safety_stock_breach'      => 'inventory_levels',
        'negative_inventory'       => 'inventory_levels',
        'overstock'                => 'inventory_levels',
        'phantom_inventory'        => 'inventory_levels',
        'dead_stock'               => 'inventory_levels',
        'multi_location_imbalance' => 'inventory_levels',
        'reorder_point_staleness'  => 'inventory_levels',
        'inventory_shrinkage'      => 'inventory_levels',
        'cumulative_shrink'        => 'inventory_levels',
        // Sales / demand
        'sales_spike'              => 'sales_transactions',
        'sales_drop'               => 'sales_transactions',
        'demand_seasonality_breach'=> 'sales_transactions',
        'demand_erosion'           => 'sales_transactions',
        'demand_forecast_break'    => 'sales_transactions',
        'cannibalization_signal'   => 'sales_transactions',
        'channel_mix_shift'        => 'sales_transactions',
        'return_rate_spike'        => 'sales_transactions',
        'plan_variance'            => 'sales_transactions',
        // Purchase orders / supplier
        'po_overdue'               => 'purchase_orders',
        'receiving_discrepancy'    => 'purchase_orders',
        'po_late_receipt'          => 'purchase_orders',
        'supplier_fill_rate'       => 'purchase_orders',
        'supplier_lead_time_drift' => 'purchase_orders',
        'order_plan_variance'      => 'purchase_orders',
    ];

    /** Latest batch state per data_type for a tenant. @return array<string,string> */
    public function datasetStates(int $tenantId): array
    {
        // Most recent import_quality row per data_type (Postgres DISTINCT ON).
        $rows = DB::select(
            "SELECT DISTINCT ON (data_type) data_type, state, blocked
             FROM import_quality
             WHERE tenant_id = ? AND state IS DISTINCT FROM 'duplicate'
             ORDER BY data_type, created_at DESC",
            [$tenantId],
        );

        $out = [];
        foreach ($rows as $r) {
            $out[$r->data_type] = $r->state;
        }

        return $out;
    }

    /** Datasets whose latest batch is RED/blocked. @return array<string,true> */
    public function blockedDatasets(int $tenantId): array
    {
        $blocked = [];
        foreach ($this->datasetStates($tenantId) as $dataType => $state) {
            if ($state === ImportQuality::STATE_RED) {
                $blocked[$dataType] = true;
            }
        }

        return $blocked;
    }

    /** Rule types to skip because their dataset is blocked. @return array<string,true> */
    public function blockedRules(int $tenantId): array
    {
        $blockedDatasets = $this->blockedDatasets($tenantId);
        if ($blockedDatasets === []) {
            return [];
        }

        $rules = [];
        foreach (self::RULE_DATASET as $rule => $dataset) {
            if (isset($blockedDatasets[$dataset])) {
                $rules[$rule] = true;
            }
        }

        return $rules;
    }

    public function isDatasetReady(int $tenantId, string $dataType): bool
    {
        return ($this->datasetStates($tenantId)[$dataType] ?? ImportQuality::STATE_GREEN) !== ImportQuality::STATE_RED;
    }

    /**
     * WP7.4 (D12): the per-dataset readiness shown on the Data Health page —
     * the latest batch of each dataset, what it decided, and which detection
     * rules it holds back. (Moved from the retired Data Readiness page.)
     *
     * @return array{overall:string, datasets:array<int,array<string,mixed>>}
     */
    public function summary(int $tenantId): array
    {

        $rows = \Illuminate\Support\Facades\DB::select(
            "SELECT DISTINCT ON (data_type) data_type, state, decision, rows_promoted, rows_quarantined,
                    reason_counts, created_at
             FROM import_quality WHERE tenant_id = ? AND state IS DISTINCT FROM 'duplicate'
             ORDER BY data_type, created_at DESC",
            [$tenantId],
        );

        $datasets = [];
        $anyRed = false;
        $anyAmber = false;

        foreach ($rows as $r) {
            $rules = array_keys(array_filter(
                self::RULE_DATASET,
                fn ($ds) => $ds === $r->data_type,
            ));

            $r->state === 'red' ? $anyRed = true : ($r->state === 'amber' ? $anyAmber = true : null);

            $datasets[] = [
                'data_type'   => $r->data_type,
                'state'       => $r->state,
                'decision'    => $r->decision,
                'promoted'    => (int) $r->rows_promoted,
                'quarantined' => (int) $r->rows_quarantined,
                'top_reason'  => $this->topReason($r->reason_counts),
                'rules'       => $rules,
                'ready'       => $r->state !== 'red',
            ];
        }

        usort($datasets, fn ($a, $b) => $a['ready'] <=> $b['ready']); // blocked first

        return [
            'overall'  => $anyRed ? 'red' : ($anyAmber ? 'amber' : 'green'),
            'datasets' => $datasets,
        ];
    }

    private function topReason($json): ?string
    {
        $counts = is_string($json) ? (json_decode($json, true) ?: []) : (is_array($json) ? $json : []);
        if ($counts === []) {
            return null;
        }
        arsort($counts);
        $key = array_key_first($counts);

        return \App\Services\DataQuality\Reasons::label((string) $key) . " ({$counts[$key]})";
    }
}
