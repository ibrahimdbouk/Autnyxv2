<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investigations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Identity
            $table->string('title');
            $table->text('description')->nullable();

            // Status machine: open → in_progress → resolved → closed
            $table->string('status')->default('open');
            // Priority: low | medium | high | critical
            $table->string('priority')->default('medium');

            // Assignment
            $table->unsignedBigInteger('assigned_team_id')->nullable();
            $table->unsignedBigInteger('assigned_user_id')->nullable();
            $table->foreign('assigned_team_id')->references('id')->on('teams')->nullOnDelete();
            $table->foreign('assigned_user_id')->references('id')->on('users')->nullOnDelete();

            // Primary entity context (denormalised from first anomaly for fast querying)
            $table->string('primary_sku')->nullable();
            $table->unsignedBigInteger('primary_store_id')->nullable();
            $table->foreign('primary_store_id')->references('id')->on('stores')->nullOnDelete();

            // AI narrative (populated by M19 AI Refactor)
            $table->text('ai_summary')->nullable();
            $table->text('ai_root_cause')->nullable();
            $table->string('ai_confidence')->nullable();  // established | probable | suspected | unknown
            $table->text('ai_recommended_action')->nullable();
            $table->timestamp('ai_generated_at')->nullable();

            // Human resolution
            $table->text('root_cause_notes')->nullable();
            $table->text('resolution_notes')->nullable();

            // Financial (M21)
            $table->decimal('revenue_at_risk', 12, 2)->nullable();
            $table->decimal('observed_recovery', 12, 2)->nullable();

            // SLA tracking
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            // Denormalised count — updated by correlation service
            $table->unsignedInteger('anomaly_count')->default(0);

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'priority']);
            $table->index(['tenant_id', 'primary_sku']);
            $table->index(['tenant_id', 'opened_at']);
            $table->index(['assigned_team_id']);
            $table->index(['assigned_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investigations');
    }
};
