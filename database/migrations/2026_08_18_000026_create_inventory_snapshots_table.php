<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('sku');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->foreign('store_id')->references('id')->on('stores')->nullOnDelete();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();

            // Snapshot values
            $table->decimal('on_hand_qty', 12, 4);
            $table->decimal('reorder_point', 12, 4)->nullable();
            $table->decimal('unit_cost', 12, 4)->nullable();
            $table->date('snapshot_date');

            // Origin
            $table->string('source')->default('import'); // import | system | manual

            $table->timestamps();

            $table->index(['tenant_id', 'sku', 'snapshot_date']);
            $table->index(['tenant_id', 'sku', 'store_id', 'snapshot_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_snapshots');
    }
};
