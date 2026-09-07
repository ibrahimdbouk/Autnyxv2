<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P4.8 — the approval queue. When orchestration routes a decision to a human
 * (approve mode, or AUTO falling back because confidence/guardrails weren't
 * satisfied), the ready-to-fire action-intent is staged here with the reason, so a
 * person approves or rejects it in one step rather than re-deriving everything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_queue', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('intent_type', 40);
            $table->string('source')->nullable();
            $table->json('request_payload');        // the canonical action-intent, ready to dispatch
            $table->text('reason')->nullable();     // why a human is in the loop

            $table->string('status', 20)->default('pending'); // pending | approved | rejected
            $table->timestamp('decided_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_queue');
    }
};
