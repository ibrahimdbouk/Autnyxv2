<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M23 / Feature 4 — Data Health Center
 *
 * One cached health snapshot per (tenant, dataset). Recomputed deterministically
 * by DataHealthService (on demand + nightly). Datasets: sales, inventory,
 * products, stores, purchase_orders, suppliers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_health_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('dataset'); // sales | inventory | products | stores | purchase_orders | suppliers

            // Overall grade
            $table->string('status')->default('no_data'); // healthy | warning | critical | no_data
            $table->decimal('score', 5, 2)->nullable();    // 0–100 composite

            // Freshness
            $table->timestamp('last_ingested_at')->nullable();
            $table->timestamp('last_record_at')->nullable();
            $table->integer('freshness_hours')->nullable();

            // Dimensions (0–100)
            $table->decimal('completeness_pct', 5, 2)->nullable();
            $table->decimal('validity_pct', 5, 2)->nullable();

            // Volume
            $table->unsignedInteger('records_received')->default(0);
            $table->unsignedInteger('records_accepted')->default(0);
            $table->unsignedInteger('records_rejected')->default(0);

            // Diagnostics
            $table->json('warnings')->nullable();
            $table->json('metrics')->nullable(); // full deterministic metric breakdown

            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'dataset']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_health_snapshots');
    }
};
