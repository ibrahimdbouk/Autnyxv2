<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incremental detection — Slice 1 (capture, ships dark).
 *
 * `detection_dirty_keys` is a durable queue of (store, SKU) subjects whose
 * underlying data changed since the last detection pass. It is POPULATED now
 * (by imports/rollbacks) but not yet CONSUMED — detection still runs the full
 * scan. Slice 2 makes the run read this queue. `tenants.last_detection_at` is
 * the watermark the full-sweep scheduler will use later; unused for now.
 *
 * See claude/incremental-detection-design.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('detection_dirty_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('store_id')->nullable();   // detection groups on store_id
            $table->string('sku')->nullable();                    // null = store/tenant-level (later slices)
            $table->string('reason')->default('import');          // import | rollback | baseline | profile | manual
            $table->timestamp('created_at')->nullable();

            // Dedupe: a subject marked many times before the next run stays one row.
            // (Both columns are non-null for import capture, so ON CONFLICT applies.)
            $table->unique(['tenant_id', 'store_id', 'sku']);
            $table->index('tenant_id');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->timestamp('last_detection_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detection_dirty_keys');
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('last_detection_at');
        });
    }
};
