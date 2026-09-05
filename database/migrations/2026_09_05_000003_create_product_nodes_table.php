<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1.1 — Canonical Product hierarchy (category → subcategory → product). Same
 * additive shape as location_nodes: a leaf 'product' node links to the products
 * row via product_id; interior nodes have none. Nothing in the current app
 * changes behaviour — this is a shared read model apps roll up through.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // category | subcategory | product  (leaf is normally 'product')
            $table->string('type', 20);

            $table->string('code')->nullable();
            $table->string('name');

            // Application-enforced tree edge (no DB self-FK — portable/re-parentable).
            $table->unsignedBigInteger('parent_id')->nullable();

            // Leaf link to the operational products row (null for interior nodes).
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            $table->json('attributes')->nullable();

            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'parent_id']);
            $table->index(['tenant_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_nodes');
    }
};
