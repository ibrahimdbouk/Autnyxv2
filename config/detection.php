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

];
