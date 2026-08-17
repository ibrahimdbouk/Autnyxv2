<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('anomalies', function (Blueprint $table) {
            // Investigation state machine
            $table->string('investigation_status')->default('detected')->after('dismissed_by');

            // Q1 — What changed?
            $table->text('ai_what')->nullable()->after('investigation_status');

            // Q2 — Why did it change? (with confidence tier)
            $table->text('ai_why')->nullable()->after('ai_what');
            $table->string('ai_confidence')->nullable()->after('ai_why'); // established|probable|suspected|unknown

            // Q3 — How big is the problem?
            $table->text('ai_how_big')->nullable()->after('ai_confidence');
            $table->string('ai_trajectory')->nullable()->after('ai_how_big'); // widening|stable|narrowing

            // Q4 — What should we do? (with evidence gate)
            $table->text('ai_action')->nullable()->after('ai_trajectory');
            $table->string('ai_recommendation_gate')->nullable()->after('ai_action'); // act|investigate|monitor

            // Q5 — Did it work?
            $table->text('ai_outcome')->nullable()->after('ai_recommendation_gate');

            // Q6 — Pattern or one-off?
            $table->text('ai_pattern')->nullable()->after('ai_outcome');
            $table->boolean('ai_is_recurring')->nullable()->after('ai_pattern');

            // Q7 — What else is connected?
            $table->json('ai_related_anomaly_ids')->nullable()->after('ai_is_recurring');
            $table->text('ai_related_summary')->nullable()->after('ai_related_anomaly_ids');

            // Meta
            $table->timestamp('ai_generated_at')->nullable()->after('ai_related_summary');
            $table->timestamp('action_taken_at')->nullable()->after('ai_generated_at');
            $table->text('action_notes')->nullable()->after('action_taken_at');
            $table->timestamp('resolved_at')->nullable()->after('action_notes');
            $table->text('resolution_notes')->nullable()->after('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::table('anomalies', function (Blueprint $table) {
            $table->dropColumn([
                'investigation_status',
                'ai_what', 'ai_why', 'ai_confidence',
                'ai_how_big', 'ai_trajectory',
                'ai_action', 'ai_recommendation_gate',
                'ai_outcome',
                'ai_pattern', 'ai_is_recurring',
                'ai_related_anomaly_ids', 'ai_related_summary',
                'ai_generated_at',
                'action_taken_at', 'action_notes',
                'resolved_at', 'resolution_notes',
            ]);
        });
    }
};
