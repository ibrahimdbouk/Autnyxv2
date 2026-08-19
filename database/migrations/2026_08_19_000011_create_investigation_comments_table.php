<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M23 / Feature 10 — Collaboration & Investigation Context
 *
 * Lightweight comments on the canonical investigation. Soft-deleted so history
 * is preserved with audit metadata. Attachments are a deferred follow-up
 * (require durable object storage — Laravel Cloud volume is ephemeral).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investigation_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('investigation_id')->constrained()->cascadeOnDelete();

            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            // Threading (optional single-level replies)
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->foreign('parent_id')->references('id')->on('investigation_comments')->nullOnDelete();

            $table->text('body');

            $table->timestamp('edited_at')->nullable();
            $table->softDeletes(); // deleted_at
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->foreign('deleted_by')->references('id')->on('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['tenant_id', 'investigation_id']);
            $table->index(['investigation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investigation_comments');
    }
};
