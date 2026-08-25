<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per (tenant, SKU, store) behavioural profile — the foundation of the best-fit
 * detection layer. A nightly `sku:profile` run classifies each item's demand
 * pattern (Syntetos–Boylan: smooth / erratic / intermittent / lumpy), volume
 * tier, lifecycle, and the forecasting model that best fits it. Detection will
 * later read this to gate which rules apply per item and to get a per-SKU
 * expectation. Phase 2 only WRITES the profile; nothing consumes it yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sku_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('sku');
            $table->unsignedBigInteger('store_id');

            $table->string('segment', 24)->default('unknown');   // smooth|erratic|intermittent|lumpy|dead|new|unknown
            $table->string('volume_tier', 4)->nullable();         // A|B|C
            $table->string('lifecycle', 16)->nullable();          // new|mature|declining
            $table->string('chosen_model', 24)->nullable();       // moving_average|ses|croston|sba|holt_winters|none

            $table->integer('window_days')->default(90);
            $table->integer('selling_days')->default(0);
            $table->decimal('total_units', 16, 4)->default(0);
            $table->decimal('total_revenue', 16, 4)->default(0);
            $table->decimal('mean_nonzero', 14, 4)->nullable();   // avg demand size on days it sells
            $table->decimal('adi', 12, 4)->nullable();            // avg demand interval (intermittency)
            $table->decimal('cv2', 12, 4)->nullable();            // squared CV of non-zero demand (lumpiness)
            $table->decimal('trend_slope', 14, 6)->nullable();    // units/day
            $table->decimal('trend_r2', 8, 4)->nullable();
            $table->boolean('has_inventory')->default(false);

            $table->json('features')->nullable();
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'sku', 'store_id'], 'sku_profiles_unique');
            $table->index(['tenant_id', 'segment']);
            $table->index(['tenant_id', 'store_id', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sku_profiles');
    }
};
