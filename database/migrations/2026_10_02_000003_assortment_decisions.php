<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assortment A3.5–A5 — the life of a range decision after the engine finds it:
 *
 *   review   (validation gate: a person marks the shadow sample sensible or not)
 *   decision (accepted / rejected, by whom, the value at that moment)
 *   task     (who does it, by when, done)
 *   result   (8 weeks after done, measured against peer stores that did not change)
 *
 * All columns are nullable and additive; assortment_gaps is new and not hot.
 * The task lives on the decision until the platform task model is generalised
 * (Task Execution build) — then these rows move to platform tasks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assortment_gaps', function (Blueprint $t) {
            $t->string('review_verdict', 16)->nullable();          // sensible | not_sensible
            $t->string('review_note', 500)->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();

            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->string('decision_note', 500)->nullable();
            $t->decimal('value_mid_at_decision', 18, 2)->nullable();

            $t->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('due_at')->nullable();
            $t->string('task_status', 16)->nullable();             // to_do | done | cancelled
            $t->timestamp('done_at')->nullable();
            $t->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();

            $t->date('measure_after')->nullable();
            $t->timestamp('measured_at')->nullable();
            $t->json('measurement')->nullable();

            $t->index(['tenant_id', 'status'], 'ag_tenant_status');
        });
    }

    public function down(): void
    {
        Schema::table('assortment_gaps', function (Blueprint $t) {
            $t->dropIndex('ag_tenant_status');
            foreach (['reviewed_by', 'decided_by', 'assignee_id', 'done_by'] as $fk) {
                $t->dropConstrainedForeignId($fk);
            }
            $t->dropColumn(['review_verdict', 'review_note', 'reviewed_at', 'decided_at', 'decision_note',
                'value_mid_at_decision', 'due_at', 'task_status', 'done_at', 'measure_after', 'measured_at', 'measurement']);
        });
    }
};
