<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3.2 — the values for tenant-declared custom dimensions (the EAV store). One row
 * per (tenant, entity_type, entity_id, attribute_key). This is how a tenant adds a
 * dimension to millions of products with no `ALTER TABLE` — the "no schema change"
 * in the P3.2 definition of done. Value is stored as text and coerced per the
 * dimension's declared data_type on read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('entity_type', 40);
            $table->unsignedBigInteger('entity_id');
            $table->string('attribute_key');
            $table->text('value')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'entity_type', 'entity_id', 'attribute_key'], 'eav_unique');
            $table->index(['tenant_id', 'entity_type', 'attribute_key'], 'eav_dimension_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_attribute_values');
    }
};
