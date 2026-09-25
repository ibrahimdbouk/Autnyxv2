<?php

return [

    /*
    | WP5.3 — dead-man's switch. An external cron monitor URL (Healthchecks.io,
    | Better Stack, Cronitor…) pinged by the hourly health check. Set the
    | monitor to expect a ping every hour with a grace period; if the scheduler
    | stops, the pings stop and the monitor alerts. Empty = no ping.
    */

    'heartbeat_url' => env('HEARTBEAT_URL', ''),
];
