<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P4.6 — per-tenant objective weighting: the blend the tenant wants optimised —
 * e.g. availability 0.5, margin 0.3, waste 0.2, working_capital 0.0. Extends the
 * P2.2 single-objective seam so outputs can be ranked against a weighted mix
 * rather than one KPI at a time. A weight is data (a row) — changing the blend is
 * an upsert, not a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('objective_weights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('objective', 40);          // availability | margin | waste | working_capital | …
            $table->double('weight')->default(1.0);
            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->unique(['tenant_id', 'objective']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('objective_weights');
    }
};
