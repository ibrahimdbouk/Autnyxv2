<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Codifies the notifications.data column-type fix.
 *
 * The initial notifications migration used Laravel's default `text` for `data`,
 * but Filament's database-notifications component queries it with the Postgres
 * JSON `->>` operator, which fails on a text column:
 *   SQLSTATE[42883]: operator does not exist: text ->> unknown
 *
 * This converts the column to json on PostgreSQL. It is idempotent — converting
 * an already-json column to json is a no-op. Non-Postgres drivers (e.g. the
 * SQLite test database) don't have this problem and are skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE json USING data::json');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE text USING data::text');
        }
    }
};
