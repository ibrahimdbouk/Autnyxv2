<?php

namespace App\Support\Database;

use Illuminate\Database\Connection;

/**
 * W9 DB tune-up — a web request never holds the database for longer than
 * database.web_statement_timeout_ms. Queued jobs and commands (console) are
 * not limited. Session-level SET is safe here because the app connects to
 * the database directly, not through a transaction pooler (checked
 * 2026-09-25); if DB_HOST ever becomes a "-pooler" endpoint this skips.
 */
final class WebStatementTimeout
{
    public static function apply(Connection $connection, bool $console): bool
    {
        $ms = (int) config('database.web_statement_timeout_ms', 60000);
        if ($console || $ms <= 0 || $connection->getDriverName() !== 'pgsql'
            || str_contains((string) $connection->getConfig('host'), '-pooler')) {
            return false;
        }
        $connection->statement("SET statement_timeout = {$ms}");

        return true;
    }
}
