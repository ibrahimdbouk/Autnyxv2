<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('anomalies', function (Blueprint $table) {
            $table->timestamp('notified_at')->nullable()->after('detected_at');
            $table->index(['tenant_id', 'notified_at']);
        });
    }

    public function down(): void
    {
        Schema::table('anomalies', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'notified_at']);
            $table->dropColumn('notified_at');
        });
    }
};
