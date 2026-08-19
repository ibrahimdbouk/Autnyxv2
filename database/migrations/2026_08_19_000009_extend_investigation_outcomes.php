<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M23 / Feature 8 — Outcome & Recovery Tracking (extends M21)
 *
 * M21 already stores analyst-entered revenue_at_risk / observed_recovery. This
 * adds the deterministic measurement layer the spec requires: explicit outcome
 * states, a SEPARATE attribution track, and reproducibility fields (baseline,
 * measurement window, calculation version). Action completion never equals
 * recovery — recovery is measured over a monitoring window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investigation_outcomes', function (Blueprint $table) {
            // not_measured | monitoring | no_material_change | partial_recovery |
            // observed_recovery | insufficient_evidence
            $table->string('outcome_state')->default('not_measured')->after('outcome_type');

            // Attribution kept separate from observed recovery
            // not_attempted | insufficient_evidence | estimated | high_confidence
            $table->string('attribution_status')->default('not_attempted')->after('outcome_state');
            $table->string('attribution_method')->nullable()->after('attribution_status');
            $table->string('evidence_strength')->nullable()->after('attribution_method'); // strong|moderate|insufficient

            // Reproducibility
            $table->date('measurement_window_start')->nullable()->after('recovery_measured_to');
            $table->date('measurement_window_end')->nullable()->after('measurement_window_start');
            $table->json('baseline_json')->nullable()->after('measurement_window_end');
            $table->json('metrics_json')->nullable()->after('baseline_json');
            $table->string('calculation_version')->nullable()->after('metrics_json');

            // Monitoring lifecycle
            $table->timestamp('monitoring_started_at')->nullable()->after('calculation_version');
            $table->timestamp('next_measurement_at')->nullable()->after('monitoring_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('investigation_outcomes', function (Blueprint $table) {
            $table->dropColumn([
                'outcome_state',
                'attribution_status',
                'attribution_method',
                'evidence_strength',
                'measurement_window_start',
                'measurement_window_end',
                'baseline_json',
                'metrics_json',
                'calculation_version',
                'monitoring_started_at',
                'next_measurement_at',
            ]);
        });
    }
};
