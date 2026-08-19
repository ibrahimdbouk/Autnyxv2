<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M23 / Feature 6 — Suppress
 *
 * Suppress = prevent a known-noisy pattern from surfacing (opening an
 * investigation / notifying) for a defined scope + period. Anomalies are still
 * detected and recorded — suppression is enforced at surfacing/correlation
 * time, preserving history and false-positive learning. Defaults to an expiry;
 * indefinite suppression is discouraged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Scope: rule | rule_store | rule_sku | rule_store_sku
            $table->string('scope_type');
            $table->string('rule_type');
            $table->string('sku')->nullable();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->foreign('store_id')->references('id')->on('stores')->nullOnDelete();

            // known_issue | planned_promotion | store_closure | maintenance |
            // known_supplier_problem | data_issue | false_positive | other
            $table->string('reason');
            $table->text('notes')->nullable();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable(); // null = indefinite (discouraged)
            $table->boolean('active')->default(true);

            // Usage visibility
            $table->unsignedInteger('match_count')->default(0);
            $table->timestamp('last_matched_at')->nullable();

            // Audit
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unsignedBigInteger('ended_by')->nullable();
            $table->foreign('ended_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('ended_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'active', 'rule_type']);
            $table->index(['tenant_id', 'sku']);
            $table->index(['tenant_id', 'store_id']);
            $table->index(['tenant_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppressions');
    }
};
