<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link each materialised cluster to its versioned set. Nullable + null-on-delete so
 * it's additive and never blocks a rebuild; populated by ClusterService on rebuild.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_clusters', function (Blueprint $table) {
            $table->foreignId('cluster_set_id')->nullable()->after('tenant_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('store_clusters', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cluster_set_id');
        });
    }
};
