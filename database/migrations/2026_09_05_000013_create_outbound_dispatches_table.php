<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2.1 — the round-trip record of every outbound decision: the canonical
 * action-intent payload we sent, which target it went to, and what came back.
 * This is what makes "an action can write back and record the round-trip"
 * auditable — every dispatch, success or failure, leaves a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('target_id')->nullable()->constrained('outbound_targets')->nullOnDelete();

            $table->string('intent_type', 40);
            $table->string('source')->nullable();       // e.g. "action:123"

            $table->json('request_payload')->nullable(); // the canonical action-intent envelope

            // pending | sent | acknowledged | failed
            $table->string('status', 20)->default('pending');
            $table->integer('response_code')->nullable();
            $table->text('response_body')->nullable();

            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'intent_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_dispatches');
    }
};
