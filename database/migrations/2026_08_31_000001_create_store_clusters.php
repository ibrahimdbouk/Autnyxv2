<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform primitive — store clustering (Platform\Intelligence\Clustering).
 *
 * Store peer groups, consumed by any app: root-cause (cluster-aware outliers),
 * assortment (peer distribution gaps), tasks (routing). Clusters are DERIVED —
 * rebuilt nightly by `clusters:rebuild` from a pluggable strategy — so this
 * holds no source-of-truth data; a rebuild wipes and recreates per (tenant, method).
 *
 * `method` records which strategy produced a cluster (attribute v0, demand v1
 * later) so strategies can coexist. `key` is the strategy's stable identity for
 * a group within a tenant+method (upsert target). Members are a plain pivot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_clusters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('method')->default('attribute'); // strategy that produced it
            $table->string('key');                           // stable identity within (tenant, method)
            $table->string('label');
            $table->json('params')->nullable();              // how the group was defined
            $table->timestamps();

            $table->unique(['tenant_id', 'method', 'key']);
            $table->index('tenant_id');
        });

        Schema::create('store_cluster_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_cluster_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();

            // A store belongs to at most one cluster per (implicitly method, via the
            // parent). Rebuilds delete the tenant+method clusters first, so members
            // never accumulate stale rows.
            $table->unique(['store_cluster_id', 'store_id']);
            $table->index('store_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_cluster_members');
        Schema::dropIfExists('store_clusters');
    }
};
