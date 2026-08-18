<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M15 — Add supplier_id FK to purchase_orders.
 *
 * The legacy free-text supplier column is kept for now (existing data preserved).
 * New POs written through the application will populate supplier_id.
 * The supplier column will be formally deprecated once a data migration
 * back-populates supplier_id from the suppliers table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('supplier_id')->nullable()->after('product_id');
            $table->foreign('supplier_id')->references('id')->on('suppliers')->nullOnDelete();
            $table->index(['tenant_id', 'supplier_id']);
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
            $table->dropIndex(['tenant_id', 'supplier_id']);
            $table->dropColumn('supplier_id');
        });
    }
};
