<?php

/*
|--------------------------------------------------------------------------
| Data retention (2e)
|--------------------------------------------------------------------------
|
| Rolling windows for the high-volume, append-only LEAF tables. The nightly
| `data:purge` command deletes rows whose date column is older than the window,
| in batches, so the table stays bounded at hundreds of millions of rows.
|
| Deliberately NOT listed (never auto-purged): anomalies, investigations,
| investigation_outcomes, actions — the product's memory, and what the recovery
| lifecycle (R1–R3) depends on. Raw transactional data is kept ~13 months (12 for
| year-over-year seasonality + a margin); audit logs are kept far longer for
| SOC 2 / ISO. Each window is env-overridable so ops can tune without a deploy.
|
| Each entry: table => ['days' => <retain>, 'column' => <date/timestamp column>].
| A row is purged when `column < now() - days`. NULLs in the column are never
| purged (unknown date = keep).
|
*/

return [

    'tables' => [
        // Raw transactional / time-series data — ~13 months.
        'sales_transactions'  => ['days' => (int) env('RETAIN_SALES_DAYS', 395),        'column' => 'date'],
        'inventory_levels'    => ['days' => (int) env('RETAIN_INVENTORY_DAYS', 395),    'column' => 'as_of_date'],
        'inventory_snapshots' => ['days' => (int) env('RETAIN_INV_SNAPSHOT_DAYS', 395), 'column' => 'snapshot_date'],

        // Daily sales aggregate — kept a little longer (baselines + YoY): ~18 months.
        'sales_daily'         => ['days' => (int) env('RETAIN_SALES_DAILY_DAYS', 550),  'column' => 'date'],

        // Import / ingestion history — staging rows are short-lived.
        'import_rows'         => ['days' => (int) env('RETAIN_IMPORT_ROWS_DAYS', 90),   'column' => 'created_at'],
        'ingestion_runs'      => ['days' => (int) env('RETAIN_INGESTION_DAYS', 395),    'column' => 'created_at'],

        // Audit log — kept long for compliance (~3 years).
        'audit_logs'          => ['days' => (int) env('RETAIN_AUDIT_DAYS', 1095),       'column' => 'created_at'],

        // Scheduled-task run history (ops health) — short.
        'job_runs'            => ['days' => (int) env('RETAIN_JOB_RUNS_DAYS', 90),      'column' => 'ran_at'],

        // Incremental-detection dirty queue — normally consumed nightly; this is
        // a safety net so a stalled consumer can't grow it without bound.
        'detection_dirty_keys' => ['days' => (int) env('RETAIN_DIRTY_KEYS_DAYS', 30),  'column' => 'created_at'],
    ],

];
