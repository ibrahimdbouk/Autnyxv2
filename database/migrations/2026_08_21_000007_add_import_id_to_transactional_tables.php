<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tag insert-based imported rows with the import that created them, so an import
 * can be rolled back (every row it added deleted in one click). Only the
 * insert-based datasets carry this — master data (products/stores/suppliers/
 * users) is imported via updateOrCreate and is idempotent, so it has no rows to
 * "undo".
 */
return new class extends Migration
{
    private array $tables = [
        'sales_transactions',
        'inventory_levels',
        'sales_returns',
        'purchase_orders',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'import_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->foreignId('import_id')->nullable()->constrained()->nullOnDelete();
                    $t->index('import_id');
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'import_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropConstrainedForeignId('import_id');
                });
            }
        }
    }
};
