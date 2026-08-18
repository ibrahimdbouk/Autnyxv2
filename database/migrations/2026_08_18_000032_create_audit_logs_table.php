<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // audit_logs is append-only — no updated_at
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Context — at least one must be set
            $table->unsignedBigInteger('investigation_id')->nullable();
            $table->foreign('investigation_id')->references('id')->on('investigations')->nullOnDelete();
            $table->unsignedBigInteger('anomaly_id')->nullable();
            $table->foreign('anomaly_id')->references('id')->on('anomalies')->nullOnDelete();
            $table->unsignedBigInteger('action_id')->nullable();
            $table->foreign('action_id')->references('id')->on('actions')->nullOnDelete();

            // Who (null = system)
            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            // What happened
            // event_type: status_changed | assigned | reassigned | escalated |
            //             action_created | action_completed | action_cancelled |
            //             evidence_added | comment_added | ai_generated | fp_dismissed
            $table->string('event_type');
            $table->string('description');
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();

            // Immutable — only created_at, no updated_at
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['investigation_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
