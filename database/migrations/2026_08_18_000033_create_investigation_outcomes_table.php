<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investigation_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investigation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Financial snapshot at resolution time
            $table->decimal('revenue_at_risk', 14, 2)->nullable()
                ->comment('AI-estimated revenue at risk at time of resolution');
            $table->decimal('observed_recovery', 14, 2)->nullable()
                ->comment('Actual revenue recovered / loss prevented, entered by analyst');
            $table->decimal('cost_to_resolve', 14, 2)->nullable()
                ->comment('Internal cost of investigation + remediation effort');

            // Recovery evidence
            $table->string('recovery_method')->nullable()
                ->comment('How recovery was measured: sales_rebound, stockout_cleared, return_rate_drop, manual_estimate');
            $table->date('recovery_measured_from')->nullable();
            $table->date('recovery_measured_to')->nullable();
            $table->text('recovery_notes')->nullable();

            // Outcome classification
            $table->string('outcome_type')->default('resolved')
                ->comment('resolved|false_positive|duplicate|escalated_to_ops|no_action_needed');
            $table->boolean('was_false_positive')->default(false);
            $table->boolean('rule_feedback_sent')->default(false)
                ->comment('Whether FP feedback was sent to the detection engine');

            // Root cause confirmation
            $table->string('confirmed_root_cause')->nullable()
                ->comment('Human-confirmed root cause (may differ from AI suggestion)');
            $table->boolean('ai_root_cause_correct')->nullable()
                ->comment('Did the AI root cause match analyst conclusion?');

            // Metadata
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();

            $table->unique('investigation_id'); // one outcome per investigation
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investigation_outcomes');
    }
};
