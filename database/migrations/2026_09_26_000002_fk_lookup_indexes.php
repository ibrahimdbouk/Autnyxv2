<?php

use App\Support\Database\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;

/**
 * W9 DB tune-up — foreign keys that were looked up (or cascaded) without an
 * index. Production statistics: `anomalies` had been scanned sequentially
 * ~543k times (6.1 billion rows read) and `investigation_entities` ~79k times
 * (1.5 billion) — investigation → anomalies, anomaly → entities / evidence /
 * actions, and every anomaly delete cascading into those tables.
 * Built CONCURRENTLY with a lock timeout — see docs/migrations.md.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** name => [table, columns, predicate] */
    private const INDEXES = [
        'anomalies_investigation_idx'   => ['anomalies', '(investigation_id)', 'investigation_id IS NOT NULL'],
        'anomalies_previous_episode_idx'=> ['anomalies', '(previous_episode_id)', 'previous_episode_id IS NOT NULL'],
        'inv_entities_anomaly_idx'      => ['investigation_entities', '(anomaly_id)', null],
        'inv_evidence_anomaly_idx'      => ['investigation_evidence', '(anomaly_id)', 'anomaly_id IS NOT NULL'],
        'actions_anomaly_idx'           => ['actions', '(anomaly_id)', 'anomaly_id IS NOT NULL'],
        'audit_logs_anomaly_idx'        => ['audit_logs', '(anomaly_id)', 'anomaly_id IS NOT NULL'],
        'audit_logs_action_idx'         => ['audit_logs', '(action_id)', 'action_id IS NOT NULL'],
        'quarantined_rows_import_idx'   => ['quarantined_rows', '(import_id)', null],
        'contract_violations_contract_idx' => ['contract_violations', '(data_contract_id)', null],
        'imports_tenant_feed_idx'       => ['imports', '(tenant_id, feed_key, created_at)', null],
    ];

    /** Redundant: (tenant_id, po_number) is the prefix of purchase_orders_natural_key. */
    private const DROP = ['purchase_orders_tenant_id_po_number_index'];

    public function up(): void
    {
        foreach (self::INDEXES as $name => [$table, $cols, $where]) {
            ConcurrentIndex::create($name, $table, $cols, $where);
        }
        foreach (self::DROP as $name) {
            ConcurrentIndex::drop($name);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            ConcurrentIndex::drop($name);
        }
        ConcurrentIndex::create('purchase_orders_tenant_id_po_number_index', 'purchase_orders', '(tenant_id, po_number)');
    }
};
