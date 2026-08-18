<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M15 — Add store_id to sku_baselines so baselines are computed at
 * (tenant, sku, store, rule, metric) granularity rather than retailer-wide.
 *
 * NULL store_id = retailer-wide fallback baseline.
 * Non-null store_id = store-level baseline (preferred when available).
 *
 * The old unique index covered (tenant_id, sku, rule_type, metric).
 * The new unique index covers (tenant_id, sku, store_id, rule_type, metric).
 * PostgreSQL treats NULLs as distinct in unique indexes, so retailer-wide
 * rows (store_id = NULL) are deduplicated at the application layer via
 * updateOrCreate with whereNull('store_id').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sku_baselines', function (Blueprint $table) {
            $table->unsignedBigInteger('store_id')->nullable()->after('sku');
            $table->foreign('store_id')->references('id')->on('stores')->nullOnDelete();

            // Replace the old 4-column unique with the new 5-column one
            $table->dropUnique('sku_baselines_unique');
            $table->unique(['tenant_id', 'sku', 'store_id', 'rule_type', 'metric'], 'sku_baselines_unique');
        });
    }

    public function down(): void
    {
        Schema::table('sku_baselines', function (Blueprint $table) {
            $table->dropUnique('sku_baselines_unique');
            $table->dropForeign(['store_id']);
            $table->dropColumn('store_id');
            $table->unique(['tenant_id', 'sku', 'rule_type', 'metric'], 'sku_baselines_unique');
        });
    }
};
