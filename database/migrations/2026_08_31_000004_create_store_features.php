<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — the store feature layer (Platform\Intelligence).
 *
 * Per-store behavioural features, computed nightly from sales + products +
 * sku_profiles. This is the durable asset: clusters are a projection of it, and
 * anomaly gating / assortment / promo modelling read the same vectors. Columns
 * are named and explainable (not an opaque vector) so a store's profile — and
 * later, why it clusters where it does — can be stated in plain language.
 *
 * v1 covers trade, basket, assortment/price and demand-shape features that the
 * data supports portably. Temporal features (weekend split, seasonality) are a
 * later additive iteration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->integer('window_days')->default(90);

            // Trade
            $table->decimal('revenue', 16, 4)->default(0);
            $table->decimal('units', 16, 4)->default(0);
            $table->integer('active_skus')->default(0);
            $table->integer('basket_count')->default(0);
            $table->decimal('avg_daily_revenue', 16, 4)->default(0);
            $table->decimal('growth_ratio', 10, 4)->nullable();   // recent 30d / prior 30d revenue

            // Basket
            $table->decimal('avg_basket_value', 14, 4)->nullable();
            $table->decimal('avg_basket_units', 12, 4)->nullable();

            // Assortment / price
            $table->decimal('avg_selling_price', 12, 4)->nullable(); // realised price point
            $table->decimal('sku_productivity', 14, 4)->nullable();  // revenue / active SKUs
            $table->decimal('promo_share', 6, 4)->nullable();        // discounted revenue share
            $table->string('top_category')->nullable();
            $table->decimal('top_category_share', 6, 4)->nullable();

            // Demand shape (aggregated from sku_profiles)
            $table->string('dominant_segment', 24)->nullable();

            // Explainability tiers — RELATIVE to the tenant's own store distribution
            $table->string('size_tier', 12)->nullable();    // small|medium|large
            $table->string('price_tier', 12)->nullable();   // value|mid|premium
            $table->string('basket_tier', 12)->nullable();  // low|mid|high
            $table->string('descriptor')->nullable();       // plain-language summary

            $table->json('features')->nullable();           // full category mix, segment mix, extensible
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'store_id']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_features');
    }
};
