<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P4.4 — intelligence evaluation snapshots. A point-in-time scorecard of how good
 * the platform's intelligence has been, computed from the P4.3 decision-memory
 * case base and persisted so quality can be trended over time. One row per
 * (dimension, key): `overall`, or per intent_type / objective. This is the
 * cross-app AI-quality spine — "which use-cases does the AI get right, and is it
 * well-calibrated?"
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('dimension', 20);        // overall | intent_type | objective
            $table->string('dim_key')->nullable();  // e.g. "reorder" / "waste"; null for overall
            $table->string('period')->nullable();

            $table->unsignedInteger('n')->default(0);          // total cases
            $table->unsignedInteger('resolved')->default(0);   // cases with a measured outcome
            $table->double('adoption_rate')->nullable();       // adopted / n
            $table->double('success_rate')->nullable();        // success / resolved
            $table->double('avg_realization')->nullable();     // avg realized/expected
            $table->double('avg_confidence')->nullable();      // avg predicted confidence
            $table->double('calibration_gap')->nullable();     // avg_confidence − success_rate (+ = overconfident)

            $table->timestamp('captured_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'dimension', 'dim_key']);
            $table->index(['tenant_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_snapshots');
    }
};
