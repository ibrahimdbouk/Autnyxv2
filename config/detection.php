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

];
