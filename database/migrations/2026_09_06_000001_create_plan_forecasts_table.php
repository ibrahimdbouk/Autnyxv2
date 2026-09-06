<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2.4 (inbound) — the tenant's planning baseline: forecasts and planned orders
 * ingested FROM their F&R or ERP planning system (RELEX / BY / Slimstock / S4 MRP
 * / D365 Master Planning / Oracle Fusion). This is the *expectation* Autnyx
 * measures reality against — the sensing layer sits above planning, it does not
 * replace it. `source` names the system of record it came from; the latest row
 * per (tenant, sku, store, target_date, horizon) is the active baseline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_forecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('sku');
            $table->unsignedBigInteger('store_id')->nullable(); // null = chain-level
            $table->date('target_date');

            $table->decimal('forecast_qty', 14, 3)->default(0);
            $table->decimal('planned_order_qty', 14, 3)->nullable();

            // relex | blue_yonder | slimstock | s4_mrp | d365_planning | oracle_fusion | …
            $table->string('source', 30);
            $table->string('source_ref')->nullable();      // upstream row / plan id
            $table->unsignedSmallInteger('horizon_days')->nullable();

            // When the upstream plan was generated (valid-time anchor).
            $table->timestamp('generated_at')->nullable();

            $table->timestamps();

            // The active-baseline lookup path.
            $table->index(['tenant_id', 'sku', 'store_id', 'target_date'], 'plan_forecasts_baseline_idx');
            $table->index(['tenant_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_forecasts');
    }
};
