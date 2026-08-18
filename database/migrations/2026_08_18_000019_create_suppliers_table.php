<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M15 — Canonical suppliers table.
 *
 * Previously, supplier was a free-text string on purchase_orders.
 * This table becomes the source of truth; purchase_orders.supplier_id FK
 * is added in the next migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code')->nullable();          // supplier's own code / external ID
            $table->string('name');
            $table->integer('lead_time_days')->nullable(); // contracted lead time
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'name']);
            $table->index(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
