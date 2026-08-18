<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('anomalies', function (Blueprint $table) {
            $table->unsignedBigInteger('investigation_id')->nullable()->after('tenant_id');
            $table->foreign('investigation_id')->references('id')->on('investigations')->nullOnDelete();
            $table->index(['tenant_id', 'investigation_id']);
        });
    }

    public function down(): void
    {
        Schema::table('anomalies', function (Blueprint $table) {
            $table->dropForeign(['investigation_id']);
            $table->dropIndex(['tenant_id', 'investigation_id']);
            $table->dropColumn('investigation_id');
        });
    }
};
