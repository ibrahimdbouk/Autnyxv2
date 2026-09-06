<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3.4 — data contracts: `data_health` becomes an ingestion *contract* with an
 * SLA. Per tenant + feed, declare the required columns, a freshness SLA, and a
 * minimum row count. Ingestion is then evaluated against the contract and
 * violations are recorded (see contract_violations) — the "ingestion violations
 * surface against a contract" half of the P3.4 definition of done.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('feed_key');                        // e.g. sales_daily, inventory
            $table->json('required_columns')->nullable();      // string[]
            $table->unsignedInteger('freshness_sla_hours')->nullable();
            $table->unsignedInteger('min_rows')->nullable();
            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->unique(['tenant_id', 'feed_key']);
            $table->index(['tenant_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_contracts');
    }
};
