<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * W9 DB tune-up.
 *
 *  - The JSON columns the app reads with operators (->>, containment) become
 *    jsonb: binary, indexable, compared by value. Each ALTER rewrites its table
 *    under a brief exclusive lock, so it runs with a short lock timeout and a
 *    few retries; a table it cannot lock is skipped with a warning (and
 *    `php artisan db:jsonb` finishes it later) rather than failing the deploy.
 *  - pg_stat_statements, when the server offers it (Neon does): the per-query
 *    statistics behind `php artisan db:top-queries`.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (\App\Console\Commands\JsonbCommand::COLUMNS as [$table, $column]) {
            \App\Console\Commands\JsonbCommand::convert($table, $column);
        }

        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_stat_statements');
        } catch (\Throwable $e) {
            Log::warning('[migrate] pg_stat_statements not available', ['error' => $e->getMessage()]);
        }
    }

    public function down(): void
    {
        // jsonb → json is lossless in the direction that matters; not reverted.
    }
};
