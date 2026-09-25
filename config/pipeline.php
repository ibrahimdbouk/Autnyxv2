<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Nightly chain (WP5.2)
    |--------------------------------------------------------------------------
    |
    | An hourly dispatcher (nightly:dispatch) starts each tenant's chain at this
    | local hour in the tenant's own timezone: aggregate → profiles → baselines →
    | detection + correlation → narrate → escalate → tidy → notify → watches →
    | data health → outcomes. The morning agents follow at morning_hour, once the
    | chain has finished. A chain missed by up to catch_up_hours (a deploy, a
    | worker restart) still starts that night.
    |
    | Deploy freeze: avoid deploying between nightly_hour and morning_hour local
    | (01:00–07:00 Asia/Dubai = 21:00–03:00 UTC). A deploy restarts the worker
    | and kills the step in flight; the next hourly tick does not re-run a night
    | that already started — run `nightly:dispatch --tenant=X --force` instead.
    |
    */

    'nightly_hour'   => (int) env('PIPELINE_NIGHTLY_HOUR', 1),
    'morning_hour'   => (int) env('PIPELINE_MORNING_HOUR', 6),
    'catch_up_hours' => (int) env('PIPELINE_CATCH_UP_HOURS', 3),

    /** Cache store for cross-machine locks (tenant detection, scheduler mutexes). */
    'lock_store'     => env('PIPELINE_LOCK_STORE', 'database'),

    /** Seconds a nightly detection step waits for an import-triggered run to finish. */
    'lock_wait'      => (int) env('PIPELINE_LOCK_WAIT', 900),
];
