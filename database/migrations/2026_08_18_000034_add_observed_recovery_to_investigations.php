<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investigations', function (Blueprint $table) {
            // observed_recovery may already exist from M16 (kept for quick access without joining outcomes)
            if (! Schema::hasColumn('investigations', 'observed_recovery')) {
                $table->decimal('observed_recovery', 14, 2)->nullable()->after('revenue_at_risk');
            }
        });
    }

    public function down(): void
    {
        Schema::table('investigations', function (Blueprint $table) {
            if (Schema::hasColumn('investigations', 'observed_recovery')) {
                $table->dropColumn('observed_recovery');
            }
        });
    }
};
