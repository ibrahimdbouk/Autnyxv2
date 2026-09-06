<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3.3 — vertical data model: lot/batch with expiry + cold-chain. This is the
 * primitive that opens pharma / FMCG: a physical lot of a SKU with a production
 * and expiry date, a cold-chain flag, and a lifecycle status. Batch/expiry
 * anomalies and traceability are built on top (see batch_movements,
 * compliance_events, and Platform\Traceability).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('sku');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('batch_code');

            $table->date('production_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('quantity', 14, 3)->default(0);   // quantity received into the lot
            $table->boolean('cold_chain')->default(false);
            $table->string('supplier_ref')->nullable();

            // active | quarantined | recalled | disposed | expired
            $table->string('status', 20)->default('active');

            $table->timestamps();

            $table->unique(['tenant_id', 'sku', 'batch_code'], 'batches_unique');
            $table->index(['tenant_id', 'expiry_date']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batches');
    }
};
