<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('escalation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investigation_id')->constrained()->cascadeOnDelete();

            // Which rule fired (null = manual escalation)
            $table->unsignedBigInteger('escalation_rule_id')->nullable();
            $table->foreign('escalation_rule_id')->references('id')->on('escalation_rules')->nullOnDelete();

            $table->string('trigger_reason');     // human-readable e.g. "Open > 48 hours with no action"
            $table->string('escalation_action');  // what was done
            $table->timestamp('triggered_at');

            // Targets at time of firing (denormalised — the rule's targets may change later)
            $table->unsignedBigInteger('to_team_id')->nullable();
            $table->foreign('to_team_id')->references('id')->on('teams')->nullOnDelete();
            $table->unsignedBigInteger('to_user_id')->nullable();
            $table->foreign('to_user_id')->references('id')->on('users')->nullOnDelete();

            // Previous state (for audit trail)
            $table->string('from_priority')->nullable();
            $table->string('to_priority')->nullable();

            $table->timestamps();

            $table->index(['investigation_id', 'triggered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escalation_events');
    }
};
