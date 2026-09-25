<?php

namespace App\Console\Commands;

use App\Services\Ops\DatabaseBackup;
use Illuminate\Console\Command;

/**
 * WP8.1 (audit L13) — the restore drill: take the latest stored backup (or
 * --backup=path), verify its checksum, restore it into a new scratch database
 * on the same server, compare every table with the live database, drop the
 * scratch database. The timings are the logical-restore RTO. Run it after
 * any change to backups and at least quarterly (docs/disaster-recovery.md).
 */
class RestoreDrillCommand extends Command
{
    protected $signature = 'db:restore-drill {--backup= : A stored backup path (default: the latest)} {--keep : Keep the scratch database}';

    protected $description = 'Restore the latest backup into a scratch database and compare it with the live one';

    public function handle(DatabaseBackup $backup): int
    {
        $r = $backup->restoreDrill($this->option('backup') ?: null, (bool) $this->option('keep'));
        $c = $r['comparison'];

        $this->info("Restored {$r['backup']} into {$r['scratch_database']}" . ($this->option('keep') ? ' (kept)' : ' (dropped)') . '.');
        $this->line(sprintf('Download %ss · restore %ss · %.1f MB · checksum %s',
            $r['download_seconds'], $r['restore_seconds'], $r['bytes'] / 1048576, $r['checksum_ok'] ? 'OK' : 'MISMATCH'));
        $this->line("Tables: live {$c['tables_live']}, restored {$c['tables_restored']}; rows: live {$c['rows_live']}, restored {$c['rows_restored']}; migrations: live {$c['migrations_live']}, restored {$c['migrations_restored']}.");
        foreach ($c['count_differences'] as $table => $d) {
            $this->line("  {$table}: live {$d['live']}, restored {$d['restored']} (written after the backup, or lost)");
        }

        if ($c['missing_tables'] !== []) {
            $this->error('Missing from the restore: ' . implode(', ', $c['missing_tables']));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
