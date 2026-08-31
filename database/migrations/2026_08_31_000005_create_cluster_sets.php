<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 — the versioned container a tenant's clusters belong to
 * (Platform\Intelligence). One active set per (tenant, strategy, objective).
 *
 * `version` increments only when a rebuild produces a materially different
 * grouping (detected via `signature`), so it's a stable reference a recommendation
 * can later stamp — "which grouping was this store in when we recommended?" —
 * without snapshotting every nightly run. Old-version RETENTION is deferred until
 * rec-stamping (Assortment) actually references a version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cluster_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('strategy');                        // attribute | demand
            $table->string('objective')->default('general');   // general | assortment | ...
            $table->unsignedInteger('version')->default(1);
            $table->string('signature')->nullable();           // grouping fingerprint
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'strategy', 'objective']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cluster_sets');
    }
};
