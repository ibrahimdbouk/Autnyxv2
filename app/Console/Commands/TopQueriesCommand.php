<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * W9 DB tune-up — the statements that cost the database the most, from
 * pg_stat_statements (constants are already replaced by $n placeholders, so
 * no data is shown). --reset clears the statistics to measure a change.
 */
class TopQueriesCommand extends Command
{
    protected $signature = 'db:top-queries {--limit=15} {--reset : Reset the statistics after printing}';

    protected $description = 'Show the most expensive SQL statements (pg_stat_statements)';

    public function handle(): int
    {
        if (! DB::selectOne("SELECT 1 AS x FROM pg_extension WHERE extname = 'pg_stat_statements'")) {
            $this->warn('pg_stat_statements is not installed (migration 2026_09_26_000003 installs it where the server allows).');

            return self::SUCCESS;
        }

        try {
            $rows = $this->top((int) $this->option('limit'));
        } catch (\Illuminate\Database\QueryException $e) {
            $this->warn('pg_stat_statements is installed but not loaded by the server (shared_preload_libraries).');

            return self::SUCCESS;
        }

        $this->table(['calls', 'total ms', 'mean ms', 'rows', 'disk blocks', 'statement'], array_map(fn ($r) => [
            $r->calls, $r->total_ms, $r->mean_ms, $r->rows, $r->disk_blocks, Str::limit(preg_replace('/\s+/', ' ', $r->query), 140),
        ], $rows));

        if ($this->option('reset')) {
            DB::statement('SELECT pg_stat_statements_reset()');
            $this->info('Statistics reset.');
        }

        return self::SUCCESS;
    }

    private function top(int $limit): array
    {
        return DB::select('SELECT calls, round(total_exec_time)::bigint AS total_ms, round(mean_exec_time::numeric, 1) AS mean_ms,
                rows, shared_blks_read AS disk_blocks, query
            FROM pg_stat_statements
            WHERE dbid = (SELECT oid FROM pg_database WHERE datname = current_database())
            ORDER BY total_exec_time DESC LIMIT ?', [$limit]);
    }
}
