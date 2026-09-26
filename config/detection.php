<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Detection run mode
    |--------------------------------------------------------------------------
    |
    | 'full'        — scan the whole catalogue every run (the original behaviour).
    | 'incremental' — scan only the SKUs that changed since the last run (the
    |                 detection_dirty_keys queue) plus the subjects of still-open
    |                 anomalies, so the recovery lifecycle keeps advancing.
    |
    | Default is 'full' so production behaviour is unchanged until incremental is
    | validated (shadow-diff) and explicitly switched on via DETECTION_MODE.
    | See claude/incremental-detection-design.md.
    |
    */

    'mode' => env('DETECTION_MODE', 'full'),

    /*
    | If an incremental run's SKU union exceeds this, the run falls back to a
    | full scan for that tenant (a very broad change set is cheaper to scan
    | whole than to filter, and it keeps the IN (...) list bounded).
    */

    'max_union_skus' => (int) env('DETECTION_MAX_UNION_SKUS', 20000),

    /*
    | WP6.3: a v2 tenant with more (store × SKU) positions than this is scanned
    | in SKU buckets of about this many positions each, so a run's memory is
    | bounded by the bucket, not the tenant.
    */

    'bucket_positions' => (int) env('DETECTION_BUCKET_POSITIONS', 250000),

    /* WP6.3: seconds a run spends linking new anomalies to investigations before leaving the rest to the next run. */
    'correlation_budget_seconds' => (int) env('DETECTION_CORRELATION_BUDGET', 1200),

    /*
    |--------------------------------------------------------------------------
    | Import-triggered (async) detection mode
    |--------------------------------------------------------------------------
    |
    | When an import completes it dispatches RunTenantDetectionJob to the queue
    | (off the web request) so new data surfaces anomalies within minutes instead
    | of waiting for the nightly 02:00 scan. This is the mode that run uses.
    |
    | 'incremental' (default) — scan only the SKUs this import touched (the dirty
    |                 keys it just recorded) plus still-open subjects: fast (seconds)
    |                 and cheap. The nightly FULL scan is the correctness backstop,
    |                 so anything incremental scoping might miss is caught same day.
    | 'full'        — full tenant scan on every import (heavier; use only if you
    |                 have not enabled the nightly full backstop).
    |
    | Requires a queue worker to be running — see claude/async-detection.md.
    |
    */

    'import_trigger_mode' => env('DETECTION_IMPORT_MODE', 'incremental'),

    /*
    |--------------------------------------------------------------------------
    | Pending-import guard (WP1.2 / audit C3)
    |--------------------------------------------------------------------------
    |
    | Detection defers while a tenant has an import that could leave it reading
    | half-loaded data — but only for a bounded time, so an abandoned upload can
    | never silently switch detection off:
    |   • importing                 → blocks while updated within N minutes
    |   • uploaded / mapping_review → blocks while created within N hours
    |     (e.g. part 2 of a two-file sales load still being mapped); after that
    |     imports:expire-abandoned marks them "abandoned" and detection proceeds.
    */

    'import_block_minutes'      => (int) env('DETECTION_IMPORT_BLOCK_MINUTES', 30),
    'pending_import_block_hours' => (int) env('DETECTION_PENDING_IMPORT_BLOCK_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Corrected rules (W4)
    |--------------------------------------------------------------------------
    |
    | The W4 rule set (store outlier on daily rates, store-joined shrink, one
    | window definition, a clock per dataset, latest-snapshot inventory, subject
    | identity for PO / supplier / receipt rules, sticky dismissals, robust
    | baselines). Off by default: `detection:diff --tenant=X` compares both sets
    | without writing, and a tenant is switched with its own
    | settings.detection_rules_v2 flag (which overrides this default either way)
    | or by `detection:recalibrate`.
    |
    */

    'rules_v2' => (bool) env('DETECTION_RULES_V2', false),

    /*
    | v2: an inventory position whose latest snapshot is this many days older
    | than the tenant's newest snapshot is stale — no longer in the feed — and
    | is not judged.
    */

    'inventory_max_age_days' => (int) env('DETECTION_INVENTORY_MAX_AGE_DAYS', 14),

    /*
    | v2 (WP4.3): a dismissed anomaly stays quiet while its condition persists,
    | unless it becomes materially worse (value × this factor, or a higher
    | severity) — and for at most this many days.
    */

    'dismissal_worsen_factor' => (float) env('DETECTION_DISMISSAL_WORSEN_FACTOR', 1.5),
    'dismissal_max_days'      => (int) env('DETECTION_DISMISSAL_MAX_DAYS', 90),

    // W10: a demand swing a promotion explains is not an anomaly — a spike
    // during one, or a drop measured against a promo-inflated baseline or in
    // the dip after it. Promotions come from the calendar import and from
    // promotion_ref on sales lines. Off = flag them as before.
    'promo_suppression'       => (bool) env('DETECTION_PROMO_SUPPRESSION', true),

    // W13: a demand swing the retail calendar explains (Ramadan, Eid, back to
    // school, Christmas, White Friday, national days, the tenant's own events)
    // is not flagged. Off = flag them as before.
    'event_suppression'       => (bool) env('DETECTION_EVENT_SUPPRESSION', true),

    /* W13: a run that writes at least this many new rows refreshes the table's planner statistics. */
    'analyze_after_rows'      => (int) env('DETECTION_ANALYZE_AFTER_ROWS', 1000),

];
