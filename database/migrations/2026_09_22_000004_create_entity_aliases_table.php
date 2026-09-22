<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entity aliases — the canonicaliser's memory. Maps a messy inbound value to its
 * canonical form so "Store 001" / "ST001" / "Downtown" all resolve to one entity,
 * and SKU variants collapse. Applied during cleansing and to resolve orphans in the
 * quarantine triage. See claude/data-quality-firewall.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('entity_type', 20); // sku | store | supplier
            $table->string('alias');           // the messy inbound value (normalised)
            $table->string('canonical');       // the value to use instead

            $table->timestamps();

            $table->unique(['tenant_id', 'entity_type', 'alias'], 'entity_aliases_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_aliases');
    }
};
