<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M23 / Feature 5 — Watch Investigation
 *
 * Dedup ledger: one row per (watch, event_signature). The evaluation job
 * checks this before dispatching a notification so the same meaningful event
 * is never sent twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watch_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('watch_id')->constrained('investigation_watches')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('investigation_id');
            $table->foreign('investigation_id')->references('id')->on('investigations')->cascadeOnDelete();

            $table->string('event_type');
            $table->string('event_signature'); // e.g. status_change:open->resolved
            $table->text('message')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            $table->unique(['watch_id', 'event_signature']);
            $table->index(['investigation_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watch_notifications');
    }
};
