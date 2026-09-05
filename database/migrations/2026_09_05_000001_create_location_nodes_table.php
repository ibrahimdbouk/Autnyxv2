<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1.1 — Canonical Platform Core: the Location hierarchy.
 *
 * An effective-dated tree (banner → region → DC → store) that every app can roll
 * up / drill down through, independent of any one app's flat `stores` table. A
 * leaf `store` node links back to the existing stores row via store_id, so this
 * is purely additive — nothing in the current app changes behaviour. Product and
 * Supplier hierarchies follow the same shape in later slices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // banner | region | dc | store  (leaf is normally 'store')
            $table->string('type', 20);

            $table->string('code')->nullable();
            $table->string('name');

            // Self-referencing tree edge. Kept as a plain indexed column (no DB-level
            // self-FK) so the hierarchy is portable across drivers and easy to
            // re-parent; integrity is enforced in the model layer.
            $table->unsignedBigInteger('parent_id')->nullable();

            // Leaf link to the operational stores row (null for interior nodes).
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();

            // Free-form descriptors (city, country, format, external refs, …).
            $table->json('attributes')->nullable();

            // Effective dating — the pattern the P1.3 bitemporal layer deepens.
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'parent_id']);
            $table->index(['tenant_id', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_nodes');
    }
};
