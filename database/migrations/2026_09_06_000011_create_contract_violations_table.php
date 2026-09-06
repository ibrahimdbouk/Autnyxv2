<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3.4 — a recorded breach of a data contract: missing required columns, a feed
 * gone stale past its SLA, an empty feed, or too few rows. This is the audit trail
 * that makes an ingestion contract enforceable rather than aspirational.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_violations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('data_contract_id')->constrained('data_contracts')->cascadeOnDelete();

            $table->string('feed_key');
            // missing_columns | stale | empty | below_min_rows
            $table->string('kind', 30);
            $table->text('detail')->nullable();

            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'resolved_at']);
            $table->index(['tenant_id', 'feed_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_violations');
    }
};
