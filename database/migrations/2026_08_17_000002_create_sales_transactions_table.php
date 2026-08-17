<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('transaction_id')->nullable();
            $table->date('date');
            $table->string('sku');
            $table->string('location')->nullable();
            $table->decimal('quantity', 12, 4);
            $table->decimal('unit_price', 12, 4)->nullable();
            $table->decimal('total_amount', 12, 4)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'transaction_id'], 'unique_tenant_transaction');
            $table->index(['tenant_id', 'date']);
            $table->index(['tenant_id', 'sku']);
            $table->index(['tenant_id', 'location']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_transactions');
    }
};
