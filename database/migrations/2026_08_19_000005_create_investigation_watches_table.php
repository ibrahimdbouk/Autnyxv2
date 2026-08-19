<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M23 / Feature 5 — Watch Investigation
 *
 * A watcher (individual user or team) subscribes to meaningful changes on an
 * investigation. last_state stores the deterministic snapshot the evaluation
 * job diffs against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investigation_watches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('investigation_id')->constrained()->cascadeOnDelete();

            // Exactly one of user_id / team_id is set
            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->foreign('team_id')->references('id')->on('teams')->nullOnDelete();

            // until_resolved | until_date | indefinite
            $table->string('mode')->default('until_resolved');
            $table->timestamp('watch_until')->nullable();

            // Subset of: status_change, escalation, action_taken, overdue,
            // material_impact_change, recovery, resolution
            $table->json('triggers')->nullable();

            $table->boolean('active')->default(true);

            // Deterministic snapshot for change detection
            $table->json('last_state')->nullable();
            $table->timestamp('last_evaluated_at')->nullable();

            // Audit
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unsignedBigInteger('ended_by')->nullable();
            $table->foreign('ended_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('ended_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'active']);
            $table->index(['investigation_id', 'active']);
            $table->index(['user_id', 'active']);
            $table->index(['team_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investigation_watches');
    }
};
