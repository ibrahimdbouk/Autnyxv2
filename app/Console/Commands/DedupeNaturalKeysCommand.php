<?php

namespace App\Console\Commands;

use App\Support\Database\NaturalKeyIndexes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * WP3.5 — data repair for the natural-key indexes.
 *
 * Finds rows that repeat a natural key and keeps the NEWEST (highest id, i.e.
 * the latest import) of each group:
 *   • inventory_levels (tenant, store, sku, as_of_date, batch)
 *   • purchase_orders  (tenant, po_number, sku, store)
 *   • sales_returns    (tenant, return_id, sku) — rows with a return id only
 * NULLs count as equal, like the indexes. Dry run by default. --apply first
 * copies the rows it will delete into a `wp35_dedupe_backup_<table>` table,
 * deletes them, then creates any index that is still missing. Idempotent.
 */
class DedupeNaturalKeysCommand extends Command
{
    protected $signature = 'imports:dedupe-natural-keys {--apply : Back up and delete the duplicates, then create the indexes}';

    protected $description = 'WP3.5: remove duplicate fact rows (keep the newest, with a backup) and create the natural-key unique indexes';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $rows = [];

        foreach (NaturalKeyIndexes::INDEXES as [$table]) {
            $count = NaturalKeyIndexes::duplicates($table);
            $deleted = '—';
            if ($apply && $count > 0) {
                $ids = NaturalKeyIndexes::duplicateIdsSql($table);
                $backup = "wp35_dedupe_backup_{$table}";
                DB::transaction(function () use ($table, $ids, $backup, &$deleted) {
                    DB::statement("CREATE TABLE IF NOT EXISTS {$backup} (LIKE {$table} INCLUDING DEFAULTS)");
                    DB::statement("INSERT INTO {$backup} SELECT * FROM {$table} WHERE id IN ({$ids})");
                    $deleted = DB::affectingStatement("DELETE FROM {$table} WHERE id IN ({$ids})");
                });
            }
            $rows[] = [$table, $count, $deleted];
        }

        $this->table(['Table', 'Duplicate rows', 'Deleted (backed up)'], $rows);

        if (! $apply) {
            $this->line('Dry run. Re-run with --apply to back up + delete (the newest row of each key is kept) and create the indexes.');

            return self::SUCCESS;
        }

        foreach (NaturalKeyIndexes::ensure() as $index => $state) {
            $this->line("{$index}: {$state}");
        }

        return self::SUCCESS;
    }
}
