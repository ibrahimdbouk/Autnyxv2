<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P4.3 — decision memory: the case base that turns Autnyx from reactive into
 * learning. Every recommendation is stored as its full arc —
 * Situation → Evidence → Recommendation → Decision → Action → Outcome — so the
 * platform can later retrieve similar past situations and answer "when this
 * pattern happened before, recommendation X succeeded N% of the time." The JSON
 * columns hold the rich objects; the flat columns (intent_type, sku, objective,
 * expected_value, outcome_status, …) are denormalised for fast filtering/scoring.
 * `situation` is a numeric feature vector used for similarity retrieval.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decision_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Denormalised keys for filtering + scoring.
            $table->string('intent_type', 40);
            $table->string('objective')->nullable();
            $table->string('sku')->nullable();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->decimal('expected_value', 16, 2)->default(0);
            $table->double('confidence')->nullable();
            $table->double('risk')->nullable();

            // The arc, as rich objects.
            $table->json('situation');                 // numeric feature vector {key: number}
            $table->json('evidence')->nullable();      // signals that justified it
            $table->json('recommendation')->nullable(); // the Recommendation envelope
            $table->json('outcome')->nullable();       // observed values

            // adopted | rejected | deferred | pending
            $table->string('decision', 20)->default('pending');
            $table->string('action_ref')->nullable();  // e.g. "action:123" / dispatch id

            // success | partial | failure | pending
            $table->string('outcome_status', 20)->default('pending');
            $table->decimal('realized_value', 16, 2)->nullable();
            $table->double('realization_rate')->nullable(); // realized / expected

            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'intent_type']);
            $table->index(['tenant_id', 'outcome_status']);
            $table->index(['tenant_id', 'sku', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decision_cases');
    }
};
