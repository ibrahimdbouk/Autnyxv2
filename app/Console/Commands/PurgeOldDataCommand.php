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
            $where  = $cfg['where'] ?? null;

            if ($dry) {
                $count = DB::table($table)->whereNotNull($column)->where($column, '<', $cutoff)
                    ->when($where, fn ($q) => $q->whereRaw($where))->count();
                $this->line("• {$table}: would delete " . number_format($count) . " row(s) older than {$cutoff->toDateString()} ({$days}d)");
                $grandTotal += $count;
                continue;
            }

            $deleted = $this->purge($table, $column, $cutoff, $chunk, $where);
            $grandTotal += $deleted;
            $this->info("• {$table}: deleted " . number_format($deleted) . " row(s) older than {$cutoff->toDateString()} ({$days}d)");
        }

        if ($only === null) {
            $files = $this->purgeImportFiles($dry);
            $this->line('• import files: ' . ($dry ? 'would delete ' : 'deleted ') . number_format($files));
        }

        $verb = $dry ? 'would delete' : 'deleted';
        $this->line('');
        $this->info("data:purge — {$verb} " . number_format($grandTotal) . ' row(s) total.');

        return self::SUCCESS;
    }

    /**
     * Batch-delete in bounded statements. WP6.6 (audit M24): tenant by tenant,
     * so each batch walks a (tenant_id, date) index instead of the whole table;
     * tables without a tenant (notifications) are purged platform-wide.
     */
    private function purge(string $table, string $column, Carbon $cutoff, int $chunk, ?string $where): int
    {
        $total = 0;
        $extra = $where ? " AND ({$where})" : '';
        $tenants = Schema::hasColumn($table, 'tenant_id')
            ? DB::table('tenants')->orderBy('id')->pluck('id')->all()
            : [null];
        $pg = DB::getDriverName() === 'pgsql';

        foreach ($tenants as $tenantId) {
            $scope = $tenantId !== null ? 'tenant_id = ? AND ' : '';
            $bind  = $tenantId !== null ? [$tenantId, $cutoff] : [$cutoff];
            do {
                $pick = "SELECT %s FROM {$table} WHERE {$scope}{$column} IS NOT NULL AND {$column} < ?{$extra} LIMIT {$chunk}";
                $deleted = DB::delete($pg
                    ? "DELETE FROM {$table} WHERE ctid = ANY(ARRAY(" . sprintf($pick, 'ctid') . '))'
                    : "DELETE FROM {$table} WHERE id IN (" . sprintf($pick, 'id') . ')',
                    $bind
                );
                $total += $deleted;

                // Breathe between large batches so we don't monopolise the DB.
                if ($deleted > 0) {
                    usleep(50_000); // 50ms
                }
            } while ($deleted > 0);
        }

        return $total;
    }

    /** WP6.6: the stored file of a finished import, once it is older than the window (the rows stay). */
    private function purgeImportFiles(bool $dry): int
    {
        $days = (int) config('retention.import_files_days', 90);
        if ($days <= 0 || ! Schema::hasColumn('imports', 'file_purged_at')) {
            return 0;
        }
        $q = DB::table('imports')->whereNotNull('path')->whereNull('file_purged_at')
            ->where('created_at', '<', Carbon::now()->subDays($days))
            ->whereIn('status', ['completed', 'completed_with_errors', 'failed', 'rolled_back', 'abandoned', 'duplicate']);
        if ($dry) {
            return $q->count();
        }

        $n = 0;
        $q->orderBy('id')->select(['id', 'disk', 'path'])->chunkById(500, function ($rows) use (&$n) {
            foreach ($rows as $i) {
                try {
                    \Illuminate\Support\Facades\Storage::disk($i->disk ?: 'local')->delete($i->path);
                } catch (\Throwable $e) {
                    report($e);
                    continue;
                }
                DB::table('imports')->where('id', $i->id)->update(['file_purged_at' => now()]);
                $n++;
            }
        });

        return $n;
    }
}
