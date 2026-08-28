<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2e — enforce data retention by deleting rows older than each table's window
 * (config/retention.php), in bounded batches so the deletes never hold a long
 * lock or build a giant transaction.
 *
 * Only the high-volume LEAF tables are purged; anomalies / investigations /
 * outcomes are never touched here. NULL date values are never purged.
 */
class PurgeOldDataCommand extends Command
{
    protected $signature = 'data:purge
        {--table= : Purge only this table (must be configured)}
        {--days= : Override the retention window for this run}
        {--chunk=10000 : Rows deleted per batch}
        {--dry-run : Report what would be deleted without deleting}';

    protected $description = 'Delete data older than its retention window (config/retention.php), in batches.';

    public function handle(): int
    {
        $tables = config('retention.tables', []);
        $only   = $this->option('table');
        $chunk  = max(100, (int) $this->option('chunk'));
        $dry    = (bool) $this->option('dry-run');
        $daysOverride = $this->option('days') !== null ? (int) $this->option('days') : null;

        if ($only !== null) {
            if (! isset($tables[$only])) {
                $this->error("Table [{$only}] is not configured for retention.");

                return self::FAILURE;
            }
            $tables = [$only => $tables[$only]];
        }

        $grandTotal = 0;

        foreach ($tables as $table => $cfg) {
            $days   = $daysOverride ?? (int) ($cfg['days'] ?? 0);
            $column = $cfg['column'] ?? 'created_at';

            if ($days <= 0) {
                $this->line("• {$table}: skipped (retention disabled — days ≤ 0)");
                continue;
            }
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                $this->line("• {$table}: skipped (table/column not found)");
                continue;
            }

            $cutoff = Carbon::now()->subDays($days);

            if ($dry) {
                $count = DB::table($table)->whereNotNull($column)->where($column, '<', $cutoff)->count();
                $this->line("• {$table}: would delete " . number_format($count) . " row(s) older than {$cutoff->toDateString()} ({$days}d)");
                $grandTotal += $count;
                continue;
            }

            $deleted = $this->purge($table, $column, $cutoff, $chunk);
            $grandTotal += $deleted;
            $this->info("• {$table}: deleted " . number_format($deleted) . " row(s) older than {$cutoff->toDateString()} ({$days}d)");
        }

        $verb = $dry ? 'would delete' : 'deleted';
        $this->line('');
        $this->info("data:purge — {$verb} " . number_format($grandTotal) . ' row(s) total.');

        return self::SUCCESS;
    }

    /**
     * Batch-delete via an id sub-select — portable (Postgres has no DELETE …
     * LIMIT) and bounded so each statement is small.
     */
    private function purge(string $table, string $column, Carbon $cutoff, int $chunk): int
    {
        $total = 0;

        do {
            $deleted = DB::delete(
                "DELETE FROM {$table} WHERE id IN ("
                . "SELECT id FROM {$table} WHERE {$column} IS NOT NULL AND {$column} < ? LIMIT {$chunk}"
                . ')',
                [$cutoff]
            );

            $total += $deleted;

            // Breathe between large batches so we don't monopolise the DB.
            if ($deleted > 0) {
                usleep(50_000); // 50ms
            }
        } while ($deleted > 0);

        return $total;
    }
}
