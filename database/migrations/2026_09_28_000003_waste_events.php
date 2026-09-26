<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W11 — waste and write-offs (the "waste" import type): what was thrown away
 * or written off, where, when and why (expired, damaged, spoiled, …). Natural
 * key (date, SKU, store, reason, reference): lines of one file that share it
 * add up; a re-sent file replaces, never doubles.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::create('waste_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('import_id')->nullable()->index();
            $t->date('date');
            $t->string('sku', 100);
            $t->unsignedBigInteger('product_id')->nullable();
            $t->unsignedBigInteger('store_id')->nullable();
            $t->string('location')->nullable();
            $t->decimal('quantity', 14, 4);
            $t->decimal('value', 18, 4)->nullable();      // at cost, as supplied (else quantity × unit cost)
            $t->string('reason', 60)->nullable();
            $t->string('waste_ref', 100)->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'date']);
            $t->index(['tenant_id', 'store_id', 'sku', 'date']);
        });

        \App\Support\Database\ConcurrentIndex::create('waste_events_natural_key', 'waste_events',
            '(tenant_id, date, sku, store_id, reason, waste_ref) NULLS NOT DISTINCT', unique: true);
    }

    public function down(): void
    {
        Schema::dropIfExists('waste_events');
    }
};
