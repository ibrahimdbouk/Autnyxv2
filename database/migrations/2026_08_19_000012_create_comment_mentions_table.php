<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M23 / Feature 10 — Collaboration & Investigation Context
 *
 * Resolved @mentions per comment, used to drive notifications. One row per
 * mentioned user (team mentions are expanded to their members at post time).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comment_mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comment_id')->constrained('investigation_comments')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('investigation_id');
            $table->foreign('investigation_id')->references('id')->on('investigations')->cascadeOnDelete();

            $table->unsignedBigInteger('mentioned_user_id')->nullable();
            $table->foreign('mentioned_user_id')->references('id')->on('users')->nullOnDelete();
            $table->unsignedBigInteger('mentioned_team_id')->nullable();
            $table->foreign('mentioned_team_id')->references('id')->on('teams')->nullOnDelete();

            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->index(['investigation_id']);
            $table->index(['mentioned_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_mentions');
    }
};
