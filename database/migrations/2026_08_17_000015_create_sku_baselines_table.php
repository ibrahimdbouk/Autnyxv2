<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sku_baselines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('sku')->nullable();        // null = rule-level (not per-SKU)
            $table->string('rule_type');
            $table->string('metric');                 // e.g. daily_sales_qty, unit_price, location_qty
            $table->float('baseline_mean')->default(0);
            $table->float('baseline_stddev')->default(0);
            $table->unsignedInteger('sample_count')->default(0);
            // Sensitivity multiplier = z-score threshold to fire (default 2.0)
            // Higher = harder to fire = fewer alerts. Widened by FP feedback.
            $table->float('sensitivity_multiplier')->default(2.0);
            $table->unsignedInteger('fp_count')->default(0); // false positive count
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'sku', 'rule_type', 'metric'], 'sku_baselines_unique');
            $table->index(['tenant_id', 'rule_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sku_baselines');
    }
};
