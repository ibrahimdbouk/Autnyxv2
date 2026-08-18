<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('escalation_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('description')->nullable();

            // What triggers this rule
            // trigger_type: time_open | unassigned | no_action | priority_threshold
            $table->string('trigger_type');
            // trigger_value: hours (for time-based), priority name (for priority_threshold)
            $table->string('trigger_value')->nullable();

            // Only fire for investigations at or above this priority (optional filter)
            // low | medium | high | critical
            $table->string('min_priority')->default('low');

            // What action to take
            // escalation_action: reassign_team | reassign_user | elevate_priority | notify_lead
            $table->string('escalation_action');

            // Optional targets for reassign actions
            $table->unsignedBigInteger('target_team_id')->nullable();
            $table->foreign('target_team_id')->references('id')->on('teams')->nullOnDelete();
            $table->unsignedBigInteger('target_user_id')->nullable();
            $table->foreign('target_user_id')->references('id')->on('users')->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0); // lower = evaluated first

            $table->timestamps();

            $table->index(['tenant_id', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escalation_rules');
    }
};
