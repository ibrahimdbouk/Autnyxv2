<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku');
            $table->string('location')->nullable();
            $table->decimal('on_hand_qty', 12, 4);
            $table->decimal('reorder_point', 12, 4)->nullable();
            $table->date('as_of_date')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'sku']);
            $table->index(['tenant_id', 'location']);
            $table->index(['tenant_id', 'as_of_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_levels');
    }
};
