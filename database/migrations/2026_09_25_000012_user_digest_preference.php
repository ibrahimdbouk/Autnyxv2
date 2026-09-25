<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** WP5.4 — each person chooses whether they get the anomaly digest (off unless they opt in). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'digest_opt_in')) {
            Schema::table('users', fn (Blueprint $t) => $t->boolean('digest_opt_in')->default(false));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'digest_opt_in')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('digest_opt_in'));
        }
    }
};
