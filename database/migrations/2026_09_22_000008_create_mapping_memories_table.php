<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 5 — learned column-mapping memory. A confirmed import teaches Autnyx the
 * mapping for that source's header signature; the next import with the same headers
 * auto-applies it deterministically (no AI call), and a drifted header set reuses the
 * overlap and flags only the new columns. See claude/data-quality-firewall.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mapping_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('data_type', 40);
            $table->string('signature', 40);          // sha1 of the sorted, normalised header set
            $table->jsonb('mappings');                // [{source_header, target_field}]
            $table->unsignedSmallInteger('header_count')->default(0);
            $table->unsignedInteger('times_seen')->default(0);
            $table->timestamp('last_used_at')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'data_type', 'signature'], 'mapping_memories_unique');
            $table->index(['tenant_id', 'data_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mapping_memories');
    }
};
