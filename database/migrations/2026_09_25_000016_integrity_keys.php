<?php

use App\Support\Database\IntegrityKeys;
use Illuminate\Database\Migrations\Migration;

/**
 * WP6.5 (audit M25, H7) — unique lookup keys (supplier name and store code,
 * case-insensitive; sku_baselines NULLS NOT DISTINCT) and the tenant foreign
 * keys four tables lacked. Nothing is repaired here: a table with duplicates
 * or rows of deleted tenants is left as it is (logged) for `db:integrity`.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        foreach (IntegrityKeys::ensure() as $key => $state) {
            if (str_starts_with($state, 'skipped') || str_starts_with($state, 'not valid')) {
                fwrite(STDERR, "  ! {$key} {$state}\n");
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys(IntegrityKeys::UNIQUE) as $name) {
            \App\Support\Database\ConcurrentIndex::drop($name);
        }
        \App\Support\Database\ConcurrentIndex::create('sku_baselines_unique', 'sku_baselines', '(tenant_id, sku, store_id, rule_type, metric)', unique: true);
        foreach (IntegrityKeys::TENANT_FK as $table) {
            \Illuminate\Support\Facades\DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_tenant_id_fk");
        }
    }
};
