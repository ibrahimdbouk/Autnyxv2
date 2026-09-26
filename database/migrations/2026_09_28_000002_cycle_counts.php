<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W11 — cycle-count lists: for each store, a ranked list of positions whose
 * stock the system doubts (phantom inventory, shrink, negative on hand), and
 * what the count found. A counted variance becomes a completed action on the
 * investigation, so the measurement follows it.
 */
return new class extends Migration
{
    /** The partial unique index is built concurrently (docs/migrations.md). */
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::create('cycle_counts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('store_id');
            $t->string('sku', 100);
            $t->unsignedBigInteger('product_id')->nullable();
            $t->unsignedBigInteger('anomaly_id')->nullable();
            $t->unsignedBigInteger('investigation_id')->nullable();
            $t->string('reason', 40);                         // the rule that raised the doubt
            $t->decimal('system_qty', 14, 4)->nullable();      // on hand per the system when listed
            $t->decimal('unit_cost', 18, 4)->nullable();
            $t->decimal('value_at_risk', 18, 4)->default(0);   // the money behind the doubt (ranking)
            $t->unsignedInteger('rank')->nullable();           // within the store's list
            $t->string('status', 12)->default('open');         // open | counted | cancelled
            $t->decimal('counted_qty', 14, 4)->nullable();
            $t->decimal('variance_qty', 14, 4)->nullable();    // counted − system
            $t->decimal('variance_value', 18, 4)->nullable();  // variance × cost
            $t->string('count_source', 12)->nullable();        // app | upload
            $t->unsignedBigInteger('counted_by')->nullable();
            $t->timestamp('counted_at')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'status', 'store_id']);
            $t->index(['tenant_id', 'counted_at']);
        });
        // One open count per position at a time.
        \App\Support\Database\ConcurrentIndex::create('cycle_counts_open_position', 'cycle_counts',
            '(tenant_id, store_id, sku)', "status = 'open'", unique: true);
    }

    public function down(): void
    {
        Schema::dropIfExists('cycle_counts');
    }
};
