<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * W10 — recovery honesty. `observed_recovery` holds whatever was entered or
 * measured; `measured_recovery` is only ever written by the measurement
 * (revenue against a counterfactual). The KPI "Recovered" counts measured
 * recovery; an analyst's figure with no measurement is shown as CLAIMED.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investigation_outcomes', function (Blueprint $t) {
            $t->decimal('measured_recovery', 18, 4)->nullable();
        });

        // Outcomes the measurement already attributed keep their figure as measured.
        DB::statement("UPDATE investigation_outcomes
            SET measured_recovery = COALESCE(
                    NULLIF(metrics_json::jsonb->>'recovered', '')::numeric,
                    NULLIF(metrics_json::jsonb->>'recovery_amount', '')::numeric,
                    observed_recovery)
            WHERE attribution_status IN ('estimated', 'high_confidence')");
        // An analyst's figure nobody measured is a claim.
        DB::statement("UPDATE investigation_outcomes SET attribution_status = 'claimed'
            WHERE observed_recovery > 0 AND measured_recovery IS NULL
              AND (attribution_status IS NULL OR attribution_status = 'not_attempted')");
    }

    public function down(): void
    {
        DB::statement("UPDATE investigation_outcomes SET attribution_status = 'not_attempted' WHERE attribution_status = 'claimed'");
        Schema::table('investigation_outcomes', fn (Blueprint $t) => $t->dropColumn('measured_recovery'));
    }
};
