<?php

namespace App\Console\Commands;

use App\Support\Database\IntegrityKeys;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * WP6.5 — report (and with --apply, repair) what stops the integrity keys:
 * duplicate derived rows (deleted, newest kept), rows of tenants that no
 * longer exist (backed up, then deleted); supplier / store duplicates are only
 * reported — they are merged (`stores:dedupe`, or by hand), never deleted.
 * Then creates any key that is now possible.
 */
class DatabaseIntegrityCommand extends Command
{
    protected $signature = 'db:integrity {--apply : Repair derived duplicates and orphan rows, then create the keys}';

    protected $description = 'Report and repair what blocks the WP6.5 unique keys and tenant foreign keys';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $rows = [];

        foreach (array_keys(IntegrityKeys::UNIQUE) as $name) {
            $d = IntegrityKeys::duplicates($name);
            $table = IntegrityKeys::UNIQUE[$name][0];
            $action = '—';
            if ($d > 0 && in_array($table, IntegrityKeys::DERIVED, true)) {
                $action = $apply ? 'deleted ' . IntegrityKeys::dedupe($name) : 'would delete (derived)';
            } elseif ($d > 0) {
                $action = 'merge needed (not deleted)';
            }
            $rows[] = [$name, $d, $action];
        }

        foreach (IntegrityKeys::TENANT_FK as $table) {
            $o = IntegrityKeys::orphans($table);
            $action = '—';
            if ($o > 0) {
                if ($apply) {
                    $backup = "wp65_orphans_{$table}";
                    DB::transaction(function () use ($table, $backup, &$action) {
                        DB::statement("CREATE TABLE IF NOT EXISTS {$backup} (LIKE {$table} INCLUDING DEFAULTS)");
                        $cond = 'tenant_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM tenants x WHERE x.id = t.tenant_id)';
                        DB::statement("INSERT INTO {$backup} SELECT t.* FROM {$table} t WHERE {$cond}");
                        $action = 'deleted ' . DB::affectingStatement("DELETE FROM {$table} t WHERE {$cond}") . " (backup {$backup})";
                    });
                } else {
                    $action = 'would delete (rows of deleted tenants)';
                }
            }
            $rows[] = ["{$table}.tenant_id", $o, $action];
        }

        $this->table(['Key', 'Blocking rows', 'Action'], $rows);

        if ($apply) {
            foreach (IntegrityKeys::ensure() as $key => $state) {
                $this->line("  {$key}: {$state}");
            }
        } else {
            $this->line('Dry run. Re-run with --apply to repair and create the keys.');
        }

        return self::SUCCESS;
    }
}
