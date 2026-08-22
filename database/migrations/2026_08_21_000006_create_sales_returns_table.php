<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Returns / refunds dataset. Previously returns could only be represented as
 * negative-quantity sales rows (with no reason). This dedicated table captures
 * returns as first-class data — store, SKU, quantity, value, date and reason —
 * powering return-rate detection and future return-reason analysis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            $table->date('date');
            $table->string('sku');
            $table->string('location')->nullable();      // raw store/location name as imported
            $table->decimal('quantity', 12, 4);          // units returned
            $table->decimal('value', 12, 4)->nullable(); // refund / return value
            $table->string('reason')->nullable();        // return reason

            $table->timestamps();

            $table->index(['tenant_id', 'date']);
            $table->index(['tenant_id', 'sku']);
            $table->index(['tenant_id', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_returns');
    }
};
