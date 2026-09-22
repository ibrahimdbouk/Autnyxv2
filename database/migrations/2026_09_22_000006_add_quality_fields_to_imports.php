<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Firewall counters on the import itself, so the import list/progress can show how
 * many rows were diverted to quarantine (distinct from the failed-row ledger).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imports', function (Blueprint $table) {
            $table->unsignedInteger('quarantined_rows')->default(0)->after('failed_rows');
        });
    }

    public function down(): void
    {
        Schema::table('imports', function (Blueprint $table) {
            $table->dropColumn('quarantined_rows');
        });
    }
};
