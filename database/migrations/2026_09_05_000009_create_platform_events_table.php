<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1.2 — the event backbone: a typed, append-only fact stream. Every material
 * thing that happens (a sale, a receipt, an inventory adjustment, an action
 * taken, an outcome recorded) is one immutable row, carrying both when it
 * happened in the business world (occurred_at, "valid time") and when we
 * recorded it (recorded_at, "system time"). Downstream projections (detection,
 * recovery, metrics) read from this, which is what makes a run reproducible.
 *
 * Append-only: rows are never updated or deleted in normal operation. source_ref
 * is the idempotency key (e.g. "action:123"), so backfill + live capture can't
 * double-record the same fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // sale | receipt | adjustment | action | outcome | anomaly | …
            $table->string('event_type', 40);

            // valid time (business world) + system time (when recorded)
            $table->timestamp('occurred_at');
            $table->timestamp('recorded_at')->useCurrent();

            // Optional subject grain — which SKU / store / product the fact concerns.
            $table->string('sku')->nullable();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();

            $table->decimal('quantity', 16, 4)->nullable();
            $table->decimal('value', 16, 4)->nullable();

            // import | detection | recovery | manual | backfill
            $table->string('source', 40)->nullable();
            // idempotency handle, e.g. "action:123", "outcome:45"
            $table->string('source_ref')->nullable();

            $table->json('payload')->nullable();

            $table->index(['tenant_id', 'event_type', 'occurred_at']);
            $table->index(['tenant_id', 'sku']);
            // NULL source_ref rows are unconstrained (nulls are distinct); rows
            // that carry one are de-duplicated per tenant.
            $table->unique(['tenant_id', 'source_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_events');
    }
};
