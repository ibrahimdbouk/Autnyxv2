<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ops console — observability primitives.
 *
 * `job_runs` records every scheduled-task run (which command, success/failure,
 * duration) so the /ops Platform Health page can show the nightly pipeline at a
 * glance. `users.last_login_at` powers the cross-tenant user directory and the
 * security view.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_runs', function (Blueprint $table) {
            $table->id();
            $table->string('command');                 // normalised task name
            $table->string('status');                  // success | failed
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('message')->nullable();       // failure summary, if any
            $table->timestamp('ran_at')->index();
            $table->timestamps();

            $table->index(['command', 'ran_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_login_at')->nullable()->after('visible_screens');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_runs');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_login_at');
        });
    }
};
