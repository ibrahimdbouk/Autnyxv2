<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_levels', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable()->after('tenant_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_levels', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\Store::class);
            $table->dropColumn('store_id');
        });
    }
};
