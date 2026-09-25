<?php

namespace App\Console\Commands;

use App\Support\Database\NaturalKeyIndexes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * WP3.5 — data repair for the natural-key indexes.
 *
 * Finds rows that repeat a natural key:
 *   • inventory_levels (tenant, store, sku, as_of_date, batch) — MERGED into
 *     the newest row: quantities summed (they are bins / lots of one
 *     position), reorder point and safety stock the maximum;
 *   • purchase_orders  (tenant, po_number, sku, store) — newest row kept;
 *   • sales_returns    (tenant, return_id, sku) — newest row kept (rows with a
 *     return id only).
 * NULLs count as equal, like the indexes. Dry run by default. --apply first
 * copies EVERY row of each duplicate group, as it was, into a
 * `wp35_dedupe_backup_<table>` table, then merges / deletes, then creates any
 * index that is still missing. Idempotent.
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
                    DB::statement("INSERT INTO {$backup} SELECT * FROM {$table} WHERE id IN (" . NaturalKeyIndexes::groupIdsSql($table) . ')');
                    if ($table === 'inventory_levels') {
                        DB::statement(NaturalKeyIndexes::mergeInventorySql());
                    }
                    $deleted = DB::affectingStatement("DELETE FROM {$table} WHERE id IN ({$ids})");
                });
            }
            $rows[] = [$table, $count, $deleted];
        }

        $this->table(['Table', 'Duplicate rows', 'Removed (inventory: merged into the kept row)'], $rows);

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
