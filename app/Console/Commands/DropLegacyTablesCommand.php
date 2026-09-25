<?php

namespace App\Console\Commands;

use App\Services\Ops\DatabaseBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * W9 DB tune-up — drop one-off repair backup tables (e.g. the W3 inventory
 * de-duplication backup) once they are older than the grace period and a
 * nightly backup newer than a day holds a copy. Dry run by default.
 */
class DropLegacyTablesCommand extends Command
{
    /** Table name patterns written by earlier one-off repairs. */
    public const PATTERNS = ['wp35_dedupe_backup_%', 'wp65_integrity_backup_%'];

    protected $signature = 'db:drop-legacy-tables {--apply : Drop them}';

    protected $description = 'Drop one-off repair backup tables (a recent full backup must exist)';

    public function handle(DatabaseBackup $backups): int
    {
        $tables = collect(self::PATTERNS)->flatMap(fn ($p) => DB::table('pg_tables')->where('schemaname', 'public')->where('tablename', 'like', $p)->pluck('tablename'))->unique()->values();
        if ($tables->isEmpty()) {
            $this->info('No legacy backup tables.');

            return self::SUCCESS;
        }

        foreach ($tables as $t) {
            $n = (int) DB::selectOne("SELECT COUNT(*) AS n FROM {$t}")->n;
            $this->line("{$t}: {$n} rows" . ($this->option('apply') ? '' : ' (would drop)'));
        }
        if (! $this->option('apply')) {
            return self::SUCCESS;
        }

        $latest = $backups->latest();
        if (! $latest || $latest['at']->lt(now()->subDay())) {
            $this->error('No database backup from the last 24h holds a copy — run php artisan db:backup first.');

            return self::FAILURE;
        }

        foreach ($tables as $t) {
            DB::statement("DROP TABLE IF EXISTS {$t}");
            $this->info("Dropped {$t} (a copy is in {$latest['path']}).");
        }

        return self::SUCCESS;
    }
}
