<?php

/*
|--------------------------------------------------------------------------
| WP8.1 (audit L13) — database backups and recovery
|--------------------------------------------------------------------------
|
| Two layers (docs/disaster-recovery.md):
|   1. Laravel Cloud point-in-time recovery (the provider's continuous WAL
|      history, 0–30 days, set per database in Cloud › Backups).
|   2. A nightly logical backup (`db:backup`): pg_dump custom format into the
|      object-storage disk, outside the database cluster, so a lost cluster,
|      a PITR window set too short or a mistake older than the window can
|      still be recovered. `db:restore-drill` proves the latest one restores.
|
*/

return [

    'enabled' => (bool) env('BACKUP_ENABLED', true),

    // Where dumps go. Laravel Cloud's attached bucket is the default disk.
    'disk' => env('BACKUP_DISK', env('FILESYSTEM_DISK', 'local')),

    'path' => 'backups/db',

    // Every dump of the last N days, then one per ISO week for N weeks.
    'keep_daily'  => (int) env('BACKUP_KEEP_DAILY', 14),
    'keep_weekly' => (int) env('BACKUP_KEEP_WEEKLY', 8),

    'pg_dump'    => env('BACKUP_PG_DUMP', 'pg_dump'),
    'pg_restore' => env('BACKUP_PG_RESTORE', 'pg_restore'),

    // Seconds a dump or restore may take before it is abandoned.
    'timeout' => (int) env('BACKUP_TIMEOUT', 3600),

    // The health check alerts when the newest successful backup is older.
    'max_age_hours' => 30,

];
