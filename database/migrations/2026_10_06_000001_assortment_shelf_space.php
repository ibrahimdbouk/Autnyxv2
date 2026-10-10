<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v1.5 Phase 4 — shelf space, from two files a tenant can give:
 *
 *   assortment_shelf_space     metres of shelf per store × category, or per
 *                              store format × category (a store's own row wins)
 *   assortment_product_space   per product: width of one facing (cm), and the
 *                              current / fewest / most facings — chain-wide
 *                              (store_key 0) or for one store (store_key = id)
 *
 * Without them the optimiser keeps counting products, and says so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assortment_shelf_space', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('format', 120)->nullable();       // slug, when the row is for a whole format
            $t->string('scope_key', 140);                // store:<id> | format:<slug>
            $t->string('category', 191);
            $t->decimal('metres', 10, 2);
            $t->timestamps();

            $t->unique(['tenant_id', 'scope_key', 'category'], 'ass_space_scope_cat');
        });

        Schema::create('assortment_product_space', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('store_key')->default(0);   // 0 = every store
            $t->string('sku');
            $t->decimal('width_cm', 8, 2)->nullable();
            $t->unsignedSmallInteger('facings')->nullable();
            $t->unsignedSmallInteger('min_facings')->nullable();
            $t->unsignedSmallInteger('max_facings')->nullable();
            $t->timestamps();

            $t->unique(['tenant_id', 'store_key', 'sku'], 'aps_scope_sku');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assortment_product_space');
        Schema::dropIfExists('assortment_shelf_space');
    }
};
