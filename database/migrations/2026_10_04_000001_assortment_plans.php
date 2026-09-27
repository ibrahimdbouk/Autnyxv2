<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v1.5 Phase 2 — the range plan (AssortmentOptimization): for one store ×
 * category, the current range against a proposed one, built by the optimiser
 * from the engine's decisions, with its changes, constraints, objective,
 * expected impact (as ranges), confidence, status and — once accepted — one
 * reset task and the measured result.
 *
 * Statuses: draft (before the plan review passes), proposed, accepted,
 * rejected, in_progress (the reset is done, waiting to be measured), measured.
 * A plan never changes the range itself: it is a proposal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assortment_plans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->string('category', 191);
            $t->string('peer_group', 64)->nullable();
            $t->string('status', 16);
            $t->string('role', 16);
            $t->string('objective', 24);
            $t->boolean('feasible')->default(true);
            $t->string('infeasible_reason', 500)->nullable();
            $t->integer('current_count');
            $t->integer('proposed_count');
            $t->json('changes');        // ordered steps: add | delist | swap | recover | protect, each with its reason and figures
            $t->json('impact');         // sales, margin, stock, space, availability: low / mid / high, and % of the shelf
            $t->json('constraints');    // limits applied, and which ones bound
            $t->decimal('value_mid', 18, 2)->default(0);   // expected sales change a year (mid), for ranking
            $t->decimal('confidence', 6, 4)->default(0);
            $t->string('confidence_tier', 16);
            $t->string('fingerprint', 64);                // the change set, so a rejected plan is not proposed again
            $t->string('optimizer_version', 32);
            $t->date('as_of_date');

            $t->string('review_verdict', 16)->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();

            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->string('decision_note', 500)->nullable();
            $t->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('due_at')->nullable();
            $t->timestamp('done_at')->nullable();
            $t->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $t->date('measure_after')->nullable();
            $t->timestamp('measured_at')->nullable();
            $t->json('measurement')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'status']);
            $t->index(['tenant_id', 'store_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assortment_plans');
    }
};
