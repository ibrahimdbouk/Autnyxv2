<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M23 / Feature 8 — Outcome & Recovery Tracking
 *
 * Append-only deterministic measurements taken during an investigation's
 * post-action monitoring window. Each row records a baseline, an expected
 * value, an observed value and the resulting outcome state for one metric —
 * fully reproducible via calculation_version + details.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outcome_measurements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('investigation_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('action_id')->nullable();
            $table->foreign('action_id')->references('id')->on('actions')->nullOnDelete();

            // sales_revenue | sales_units | availability | return_rate | po_fulfillment
            $table->string('metric_type');

            $table->decimal('baseline_value', 16, 4)->nullable(); // before / expected-normal
            $table->decimal('expected_value', 16, 4)->nullable(); // counterfactual if unresolved
            $table->decimal('observed_value', 16, 4)->nullable(); // measured post-action
            $table->decimal('delta_value', 16, 4)->nullable();    // observed - baseline
            $table->decimal('recovery_amount', 14, 2)->nullable();// deterministic $ recovery (observed only)

            $table->date('window_start')->nullable();
            $table->date('window_end')->nullable();

            // not_measured | monitoring | no_material_change | partial_recovery |
            // observed_recovery | insufficient_evidence
            $table->string('outcome_state')->default('monitoring');

            $table->string('calculation_version')->default('v1');
            $table->json('details')->nullable();

            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'investigation_id']);
            $table->index(['investigation_id', 'metric_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outcome_measurements');
    }
};
