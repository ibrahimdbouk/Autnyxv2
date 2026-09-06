<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2.4 (outbound) — the signal feed Autnyx publishes back INTO the tenant's
 * planning system: exceptions, root-cause findings, and recovery signals. This is
 * the continuous sensing feed (distinct from P2.1's single action-intent
 * write-back). A row is published when detected and stamped `consumed_at` once the
 * downstream system has pulled it, so the feed is an at-least-once, ack'd stream.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planning_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // exception | root_cause | recovery | forecast_override
            $table->string('signal_type', 30);

            $table->string('sku')->nullable();
            $table->unsignedBigInteger('store_id')->nullable();

            $table->string('severity', 20)->default('info'); // info | warning | critical
            $table->decimal('delta', 14, 3)->nullable();      // observed − expected, when quantitative
            $table->text('rationale')->nullable();
            $table->string('objective')->nullable();
            $table->string('source')->nullable();             // e.g. "investigation:123"

            $table->timestamp('detected_at')->nullable();
            $table->timestamp('consumed_at')->nullable();     // set when the feed row is pulled/ack'd

            $table->timestamps();

            $table->index(['tenant_id', 'consumed_at']);       // the "unconsumed feed" query
            $table->index(['tenant_id', 'signal_type', 'detected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planning_signals');
    }
};
