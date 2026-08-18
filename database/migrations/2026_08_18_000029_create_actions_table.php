<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investigation_id')->constrained()->cascadeOnDelete();

            // Action may relate to a specific anomaly within the investigation
            $table->unsignedBigInteger('anomaly_id')->nullable();
            $table->foreign('anomaly_id')->references('id')->on('anomalies')->nullOnDelete();

            // Action classification
            // action_type: reorder | transfer | price_adjustment | supplier_contact |
            //              write_off | discount_removal | monitor | investigate_further | other
            $table->string('action_type');
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('notes')->nullable();

            // Status machine: pending → in_progress → completed | cancelled
            $table->string('status')->default('pending');

            // Assignment
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();
            $table->unsignedBigInteger('assigned_team_id')->nullable();
            $table->foreign('assigned_team_id')->references('id')->on('teams')->nullOnDelete();

            // Authorship
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            // Timing
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->index(['investigation_id', 'status']);
            $table->index(['assigned_to', 'status']);
            $table->index(['assigned_team_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actions');
    }
};
