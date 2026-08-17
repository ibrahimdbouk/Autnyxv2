<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('po_number');
            $table->string('supplier');
            $table->string('sku');
            $table->decimal('qty_ordered', 12, 4);
            $table->decimal('qty_received', 12, 4)->nullable();
            $table->decimal('unit_cost', 12, 4)->nullable();
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->date('received_date')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'po_number']);
            $table->index(['tenant_id', 'supplier']);
            $table->index(['tenant_id', 'order_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
