<?php

namespace App\Console\Commands;

use App\Services\Ops\DatabaseBackup;
use Illuminate\Console\Command;

/**
 * WP8.1 (audit L13) — nightly logical backup into object storage, then the
 * retention prune. `--list` shows what is stored.
 */
class DatabaseBackupCommand extends Command
{
    protected $signature = 'db:backup {--list : List stored backups} {--prune-only : Only apply the retention}';

    protected $description = 'Back up the database (pg_dump) to object storage and prune old backups';

    public function handle(DatabaseBackup $backup): int
    {
        if ($this->option('list')) {
            $this->table(['Backup', 'Taken (UTC)', 'Size'], array_map(fn ($b) => [
                $b['path'], $b['at']->format('Y-m-d H:i'), number_format($b['bytes'] / 1048576, 1) . ' MB',
            ], $backup->list()));

            return self::SUCCESS;
        }

        if (! config('backup.enabled') && ! $this->option('prune-only')) {
            $this->warn('Backups are disabled (BACKUP_ENABLED=false).');

            return self::SUCCESS;
        }

        if (! $this->option('prune-only')) {
            $r = $backup->run();
            $this->info(sprintf('Backed up %d tables to %s (%.1f MB, %ss, sha256 %s…).',
                $r['tables'], $r['path'], $r['bytes'] / 1048576, $r['seconds'], substr($r['sha256'], 0, 12)));
        }

        $deleted = $backup->prune();
        $this->line('Retention: ' . count($deleted) . ' old backup(s) deleted.');

        return self::SUCCESS;
    }
}
