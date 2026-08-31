<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 seam — a store's peers depend on the question (assortment vs benchmark
 * vs promo), so a tenant will eventually hold several coexisting cluster sets.
 * `objective` is that dimension. Everything today is 'general'; the column exists
 * now so ClusterService's read API can be objective-aware without a later rewrite.
 * The full ClusterSet/version/pins model stays deferred (Phases 4–5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_clusters', function (Blueprint $table) {
            $table->string('objective')->default('general')->after('method');
        });

        Schema::table('store_clusters', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'method', 'key']);
            $table->unique(['tenant_id', 'method', 'objective', 'key']);
        });
    }

    public function down(): void
    {
        Schema::table('store_clusters', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'method', 'objective', 'key']);
            $table->unique(['tenant_id', 'method', 'key']);
        });

        Schema::table('store_clusters', function (Blueprint $table) {
            $table->dropColumn('objective');
        });
    }
};
