<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3.2 — declared custom dimensions/attributes a tenant can attach to a canonical
 * entity (product / store / supplier / …) WITHOUT altering that entity's table.
 * This is the governance half: a dimension must be declared here before values
 * can be stored against it (see entity_attribute_values), so custom attributes
 * are typed and enumerable, not a free-for-all JSON blob.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_attribute_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('entity_type', 40);     // product | store | supplier | …
            $table->string('key');                 // e.g. "temperature_zone"
            $table->string('label');
            $table->string('data_type', 20)->default('string'); // string|number|boolean|date
            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->unique(['tenant_id', 'entity_type', 'key']);
            $table->index(['tenant_id', 'entity_type', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_attribute_definitions');
    }
};
