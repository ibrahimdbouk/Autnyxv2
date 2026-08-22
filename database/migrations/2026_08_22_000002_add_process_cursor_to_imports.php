<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a progress cursor to imports so large files can be processed in
 * resumable chunks (poll-driven) instead of one long synchronous request
 * that exhausts memory / times out on the 512 MB instance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imports', function (Blueprint $table) {
            if (! Schema::hasColumn('imports', 'process_cursor')) {
                $table->unsignedInteger('process_cursor')->default(0)->after('failed_rows');
            }
        });
    }

    public function down(): void
    {
        Schema::table('imports', function (Blueprint $table) {
            if (Schema::hasColumn('imports', 'process_cursor')) {
                $table->dropColumn('process_cursor');
            }
        });
    }
};
