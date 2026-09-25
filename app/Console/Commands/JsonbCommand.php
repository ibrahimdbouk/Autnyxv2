<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * W9 DB tune-up — convert the JSON columns the app queries to jsonb. Safe to
 * re-run: a column already jsonb is left alone; a table that can't be locked
 * within the timeout is skipped and reported.
 */
class JsonbCommand extends Command
{
    /** @var array<int,array{0:string,1:string}> */
    public const COLUMNS = [
        ['anomalies', 'context'],
        ['tenants', 'settings'],
        ['investigation_evidence', 'value_json'],
        ['platform_events', 'payload'],
        ['sku_profiles', 'features'],
    ];

    protected $signature = 'db:jsonb';

    protected $description = 'Convert the queried JSON columns to jsonb (idempotent)';

    public function handle(): int
    {
        foreach (self::COLUMNS as [$table, $column]) {
            $this->line("{$table}.{$column}: " . self::convert($table, $column));
        }

        return self::SUCCESS;
    }

    /** @return string converted | already jsonb | missing | skipped (lock) */
    public static function convert(string $table, string $column, int $attempts = 4): string
    {
        $type = DB::selectOne('SELECT data_type FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?', [$table, $column])?->data_type;
        if ($type === null) {
            return 'missing';
        }
        if ($type === 'jsonb') {
            return 'already jsonb';
        }

        for ($try = 1; $try <= $attempts; $try++) {
            try {
                DB::transaction(function () use ($table, $column) {
                    DB::statement("SET LOCAL lock_timeout = '3s'");
                    DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE jsonb USING {$column}::jsonb");
                });

                return 'converted';
            } catch (\Throwable $e) {
                Log::warning('[jsonb] attempt failed', ['table' => $table, 'attempt' => $try, 'error' => $e->getMessage()]);
                sleep(min(5, $try * 2));
            }
        }

        return 'skipped (lock) — run php artisan db:jsonb later';
    }
}
