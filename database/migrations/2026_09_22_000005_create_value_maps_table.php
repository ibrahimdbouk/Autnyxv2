<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Value dictionaries — normalise the *content* of a field (not its column). E.g.
 * payment_method "CC"/"credit"→"card", a return reason free-text → a canonical set,
 * a unit-of-measure synonym. Applied per (data_type, field) during cleansing.
 * See claude/data-quality-firewall.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('value_maps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('data_type', 40);
            $table->string('field', 60);
            $table->string('from_value');   // normalised (lower/trim) match
            $table->string('to_value');

            $table->timestamps();

            $table->unique(['tenant_id', 'data_type', 'field', 'from_value'], 'value_maps_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('value_maps');
    }
};
