<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assortment Intelligence (App 2) — the engine's own tables, all new and none
 * hot (see docs/migrations.md), tenant-scoped with cascade so erase/export
 * pick them up:
 *
 *   • assortment_store_ranges — which products each store carries, and when
 *     each started and stopped, rebuilt from sales + stock history;
 *   • assortment_benchmarks   — per peer group × SKU, how peer stores that
 *     carry it (and keep it in stock) sell it;
 *   • assortment_gaps         — the typed decisions (add / delist /
 *     stockout-hidden), status 'shadow' until the validation gate passes;
 *   • assortment_must_stock   — products never proposed for delisting;
 *   • assortment_runs         — one row per engine run with its counts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assortment_store_ranges', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->string('sku');
            $t->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $t->boolean('carried')->default(false);
            $t->string('source', 12)->default('inferred');   // inferred | listing
            $t->date('first_seen')->nullable();                // first stock or sale
            $t->date('last_seen')->nullable();                 // last stock > 0 or sale
            $t->date('last_sale')->nullable();
            $t->date('last_in_stock')->nullable();
            $t->date('as_of_date');
            $t->timestamps();

            $t->unique(['tenant_id', 'store_id', 'sku'], 'asr_position');
            $t->index(['tenant_id', 'sku'], 'asr_tenant_sku');
        });

        Schema::create('assortment_benchmarks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('peer_group', 120);                     // cluster:<id> | format:<slug>
            $t->string('peer_basis', 12);                      // cluster | format
            $t->string('sku');
            $t->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedSmallInteger('group_size');
            $t->unsignedSmallInteger('carrying');              // peers that carry it now
            $t->unsignedSmallInteger('qualifying');            // carried ≥ 28 days, availability ≥ 80%
            $t->decimal('carried_share', 6, 4);
            $t->decimal('share_index_p25', 18, 10)->nullable();
            $t->decimal('share_index_median', 18, 10)->nullable();
            $t->decimal('share_index_p75', 18, 10)->nullable();
            $t->decimal('units_per_day_median', 14, 4)->nullable();    // per in-stock day
            $t->decimal('revenue_per_day_median', 18, 4)->nullable();  // per in-stock day
            $t->decimal('availability_median', 6, 4)->nullable();
            $t->date('as_of_date');
            $t->unsignedSmallInteger('window_days');
            $t->timestamps();

            $t->unique(['tenant_id', 'peer_group', 'sku'], 'ab_group_sku');
        });

        Schema::create('assortment_gaps', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->string('sku');
            $t->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $t->string('type', 20);                            // add | delist | stockout_hidden
            $t->string('status', 20)->default('shadow');       // shadow | open | accepted | rejected
            $t->string('peer_group', 120);
            $t->decimal('value_low', 18, 2)->default(0);
            $t->decimal('value_mid', 18, 2)->default(0);
            $t->decimal('value_high', 18, 2)->default(0);
            $t->decimal('confidence', 5, 4)->default(0);
            $t->string('confidence_tier', 12);                 // established | likely | speculative
            $t->json('evidence')->nullable();
            $t->json('explanation')->nullable();
            $t->date('as_of_date');
            $t->timestamp('first_detected_at')->nullable();
            $t->timestamp('last_detected_at')->nullable();
            $t->timestamps();

            $t->unique(['tenant_id', 'store_id', 'sku', 'type'], 'ag_position_type');
            $t->index(['tenant_id', 'type', 'status'], 'ag_tenant_type_status');
        });

        Schema::create('assortment_must_stock', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('sku');
            $t->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();   // null = every store
            $t->string('reason', 160)->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'sku'], 'ams_tenant_sku');
        });

        Schema::create('assortment_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->date('as_of_date')->nullable();
            $t->string('status', 12);                          // success | skipped | failed
            $t->json('stats')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'created_at'], 'arun_tenant_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assortment_runs');
        Schema::dropIfExists('assortment_must_stock');
        Schema::dropIfExists('assortment_gaps');
        Schema::dropIfExists('assortment_benchmarks');
        Schema::dropIfExists('assortment_store_ranges');
    }
};
