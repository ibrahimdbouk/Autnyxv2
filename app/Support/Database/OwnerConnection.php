<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * WP8.1 (audit L13) — least privilege. The application runs as a role that
 * can read and write rows and nothing else (DB_USERNAME, created by
 * `db:runtime-role`). The commands that change the schema, create or drop
 * databases, or dump the whole database run as the owner role
 * (DB_OWNER_USERNAME / DB_OWNER_PASSWORD): the connection's credentials are
 * swapped when such a command starts. With no owner configured nothing
 * changes — the app keeps using DB_USERNAME for everything.
 */
final class OwnerConnection
{
    /** @var array<int,string> */
    public const OWNER_COMMANDS = [
        'migrate', 'migrate:fresh', 'migrate:install', 'migrate:refresh', 'migrate:reset', 'migrate:rollback', 'migrate:status',
        'db:wipe', 'schema:dump',
        'db:runtime-role', 'db:backup', 'db:restore-drill', 'db:integrity',
        'imports:dedupe-natural-keys', 'detection:load-test',
        'db:jsonb', 'db:drop-legacy-tables', 'db:top-queries',
    ];

    public static function configured(): bool
    {
        return (string) config('database.owner.username') !== '';
    }

    public static function needsOwner(?string $command): bool
    {
        return $command !== null && in_array($command, self::OWNER_COMMANDS, true);
    }

    /** Switch the default connection to the owner's credentials (idempotent). */
    public static function use(): bool
    {
        if (! self::configured()) {
            return false;
        }

        $name = config('database.default');
        config([
            "database.connections.{$name}.username" => config('database.owner.username'),
            "database.connections.{$name}.password" => config('database.owner.password'),
        ]);
        DB::purge($name);

        return true;
    }
}
