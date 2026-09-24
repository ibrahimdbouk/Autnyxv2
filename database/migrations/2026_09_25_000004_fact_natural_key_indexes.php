<?php

use App\Support\Database\NaturalKeyIndexes;
use Illuminate\Database\Migrations\Migration;

/**
 * WP3.5 (audit H27) — natural-key unique indexes on inventory_levels,
 * purchase_orders and sales_returns, built CONCURRENTLY outside a transaction.
 *
 * A table that still holds duplicate rows is SKIPPED (logged) rather than
 * repaired here — a data repair is never done inside a migration. Run
 * `php artisan imports:dedupe-natural-keys --apply`, which backs the
 * duplicates up, removes them and then creates the remaining index.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        foreach (NaturalKeyIndexes::ensure() as $index => $state) {
            if (str_starts_with($state, 'skipped')) {
                fwrite(STDERR, "  ! {$index} {$state}\n");
            }
        }
    }

    public function down(): void
    {
        NaturalKeyIndexes::drop();
    }
};
