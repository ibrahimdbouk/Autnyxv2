<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W10 — a promotion calendar. One row per promotion × SKU (× store; no store
 * = every store). Detection reads it to leave promo-driven spikes and the
 * dip after a promotion alone. Natural key (promotion, SKU, store): a re-sent
 * calendar updates, never doubles.
 */
return new class extends Migration
{
    /** The natural-key index is built concurrently (docs/migrations.md). */
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('import_id')->nullable()->index();
            $t->string('promotion_ref', 100);
            $t->string('name')->nullable();
            $t->string('sku', 100);
            $t->unsignedBigInteger('product_id')->nullable();
            $t->unsignedBigInteger('store_id')->nullable();
            $t->string('location')->nullable();
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('mechanic', 100)->nullable();
            $t->decimal('discount_pct', 8, 3)->nullable();
            $t->decimal('promo_price', 18, 4)->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'sku', 'starts_on']);
            $t->index(['tenant_id', 'ends_on']);
        });

        \App\Support\Database\ConcurrentIndex::create('promotions_natural_key', 'promotions',
            '(tenant_id, promotion_ref, sku, store_id) NULLS NOT DISTINCT', unique: true);
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
