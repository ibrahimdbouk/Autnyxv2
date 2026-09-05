<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1.1 — Canonical Supplier hierarchy (group → supplier). Interior 'group' nodes
 * are derived from the supplier's business type; the leaf 'supplier' node links
 * to the suppliers row via supplier_id. Additive, same shape as the Location and
 * Product hierarchies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // group | supplier  (leaf is normally 'supplier')
            $table->string('type', 20);

            $table->string('code')->nullable();
            $table->string('name');

            $table->unsignedBigInteger('parent_id')->nullable();

            // Leaf link to the operational suppliers row (null for interior nodes).
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();

            $table->json('attributes')->nullable();

            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'parent_id']);
            $table->index(['tenant_id', 'supplier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_nodes');
    }
};
