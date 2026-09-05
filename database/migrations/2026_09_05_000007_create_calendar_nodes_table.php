<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1.1 — Canonical Calendar / time dimension (year → quarter → month → day).
 * Unlike the entity hierarchies this is *generated* (see `calendar:build`) rather
 * than backfilled from a table, so it is fully populated regardless of source
 * data. A leaf 'day' node carries its date; ISO week lives as a day attribute
 * (weeks cross month boundaries, so they are not a nesting tier). Metrics and
 * detection roll up week-over-week / period-to-date through this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // year | quarter | month | day
            $table->string('type', 10);

            // canonical code: '2026', '2026-Q1', '2026-03', '2026-03-15'
            $table->string('code', 20);
            $table->string('name');

            $table->unsignedBigInteger('parent_id')->nullable();

            // Set only on 'day' leaves.
            $table->date('date')->nullable();

            $table->json('attributes')->nullable();

            $table->timestamps();

            // Idempotency for the generator + fast lookup.
            $table->unique(['tenant_id', 'type', 'code']);
            $table->index(['tenant_id', 'parent_id']);
            $table->index(['tenant_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_nodes');
    }
};
