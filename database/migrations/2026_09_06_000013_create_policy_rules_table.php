<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P4.1 — the guardrail layer. A tenant-defined hard constraint on what the
 * platform may do: "never delist a strategic SKU", "no cross-temp-zone transfer",
 * "max €X inventory movement", "min 80% confidence before auto-execution".
 * `condition` is the SAME safe boolean AST as P3.2 (evaluated by
 * Platform\Extensibility\Expression\Evaluator) against an action-intent's fields;
 * when it holds, the policy is violated and `effect` decides what happens
 * (block / warn / require approval). Adding a guardrail is an INSERT, no schema
 * change — and it is the prerequisite for any autonomy (P4.8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('key');
            $table->string('label');
            // block | warn | require_approval
            $table->string('effect', 20)->default('block');
            $table->json('condition');            // safe boolean AST; true = violated
            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
            $table->index(['tenant_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_rules');
    }
};
