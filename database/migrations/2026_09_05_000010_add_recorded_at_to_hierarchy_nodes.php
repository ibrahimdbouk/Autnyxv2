<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P1.3 — add the system-time axis (recorded_at) to the operational canonical
 * hierarchies, making them bitemporal alongside the existing valid-time columns
 * (effective_from / effective_to). Existing rows inherit their created_at as the
 * recorded_at, so history is consistent. Additive.
 */
return new class extends Migration
{
    /** @var array<int,string> */
    private array $tables = ['location_nodes', 'product_nodes', 'supplier_nodes'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasColumn($table, 'recorded_at')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->timestamp('recorded_at')->nullable()->after('effective_to');
                });
            }

            DB::table($table)->whereNull('recorded_at')->update([
                'recorded_at' => DB::raw('created_at'),
            ]);
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasColumn($table, 'recorded_at')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('recorded_at'));
            }
        }
    }
};
