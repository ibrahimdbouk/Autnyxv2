<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v1.5 Phase 5 — learning. A calibration is a versioned set of adjustments to
 * the Assortment rules, worked out from measured results (expected against
 * actual) and proposed — never applied until an admin approves it:
 *
 *   value_scale   adds and stockout fixes: the estimate × factor (measured ÷ estimated)
 *   range_widen   how wide the low–high ranges are, so ~80% of results fall inside
 *   tier_shift    a rule whose decisions work less often than their confidence says
 *                 is one confidence tier lower
 *   share_shift   transferable demand: where buyers go, shifted by what delists showed
 *
 * One approved calibration is active per tenant; approving another supersedes
 * it; retiring it puts the rules back as built. Every decision and plan made
 * under one keeps its version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assortment_calibrations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('version');
            $t->string('status', 12);                 // proposed | approved | rejected | superseded | retired
            $t->json('adjustments');                  // the full set, absolute (identity = the rules as built)
            $t->json('previous');                     // what was active when it was proposed
            $t->json('reasons');                      // one line per adjustment, in words
            $t->json('basis');                        // the measured results it rests on
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->string('decision_note', 500)->nullable();
            $t->timestamps();

            $t->unique(['tenant_id', 'version']);
            $t->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assortment_calibrations');
    }
};
