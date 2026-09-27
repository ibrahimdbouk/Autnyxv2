<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v1.5 Phase 0 + 1 — two platform read models, rebuilt by their services:
 *
 *   product_lifecycles  one row per product chain-wide (store_id null) and per
 *                       store × product: new | emerging | established |
 *                       declining | seasonal | end_of_life, with the figures it
 *                       was set from. Any app reads it (Assortment first).
 *
 *   demand_transfers    OBSERVED transferable demand: of product A's demand when
 *                       A is not on the shelf, the share that moves to product B,
 *                       per pool of comparable stores, with the evidence it was
 *                       estimated from (stockout days, range changes). Only
 *                       pairs with evidence are stored; the similarity
 *                       assumption is computed when read, never stored as fact.
 *
 * Both tables are new and only written by their nightly rebuild.
 */
return new class extends Migration
{
    /** The natural-key index is built concurrently (docs/migrations.md). */
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::create('product_lifecycles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('store_id')->nullable();   // null = chain-wide
            $t->string('sku', 100);
            $t->string('state', 16);
            $t->string('reason', 200)->nullable();
            $t->date('first_sale')->nullable();
            $t->date('last_activity')->nullable();
            $t->integer('age_days')->nullable();
            $t->decimal('recent_units', 16, 4)->nullable();   // last 13 weeks
            $t->decimal('prior_units', 16, 4)->nullable();    // the 13 weeks before
            $t->decimal('peak_share', 6, 4)->nullable();      // share of a year's units in its busiest 13 weeks
            $t->date('as_of_date');
            $t->timestamps();

            $t->index(['tenant_id', 'state']);
        });
        \App\Support\Database\ConcurrentIndex::create('product_lifecycles_key', 'product_lifecycles',
            '(tenant_id, store_id, sku) NULLS NOT DISTINCT', unique: true);

        Schema::create('demand_transfers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('pool', 64);                // the comparable-store pool (e.g. an Assortment peer group)
            $t->string('from_sku', 100);           // A: the product that is not on the shelf
            $t->string('to_sku', 100);             // B: where its buyers go
            $t->string('basis', 24);               // stockouts | range_changes | both
            $t->decimal('share', 8, 4);            // observed share of A's demand moving to B
            $t->decimal('share_se', 8, 4);         // its standard error
            $t->integer('stores')->default(0);
            $t->integer('store_days')->default(0); // full-day stockouts of A observed with B in stock
            $t->integer('events')->default(0);     // range changes of A observed with B on the shelf
            $t->decimal('base_units', 16, 4)->default(0);   // A's expected units behind the estimate
            $t->date('period_from');
            $t->date('period_to');
            $t->string('model_version', 32);
            $t->timestamps();

            $t->unique(['tenant_id', 'pool', 'from_sku', 'to_sku'], 'demand_transfers_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demand_transfers');
        Schema::dropIfExists('product_lifecycles');
    }
};
