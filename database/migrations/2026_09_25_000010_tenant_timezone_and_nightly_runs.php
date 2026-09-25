<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WP5.2 (D3, audit H3) — each tenant's timezone (existing tenants: Asia/Dubai)
 * and one row per tenant per local night: the nightly chain's status and the
 * result of each step, so a chain is dispatched once, is visible in Ops, and
 * the morning agents start only after it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET lock_timeout = '5s'");
        }
        if (! Schema::hasColumn('tenants', 'timezone')) {
            Schema::table('tenants', fn (Blueprint $t) => $t->string('timezone', 64)->default('Asia/Dubai'));
        }
        if (! Schema::hasColumn('job_runs', 'tenant_id')) {
            Schema::table('job_runs', fn (Blueprint $t) => $t->unsignedBigInteger('tenant_id')->nullable()->index());
        }
        if (! Schema::hasTable('tenant_nightly_runs')) {
            Schema::create('tenant_nightly_runs', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $t->date('local_date');
                $t->string('status', 16)->default('queued'); // queued | running | done | failed
                $t->json('steps')->nullable();              // step => {status, ms, message}
                $t->timestamp('started_at')->nullable();
                $t->timestamp('finished_at')->nullable();
                $t->timestamp('agents_dispatched_at')->nullable();
                $t->timestamps();
                $t->unique(['tenant_id', 'local_date']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_nightly_runs');
        if (Schema::hasColumn('job_runs', 'tenant_id')) {
            Schema::table('job_runs', fn (Blueprint $t) => $t->dropColumn('tenant_id'));
        }
        if (Schema::hasColumn('tenants', 'timezone')) {
            Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn('timezone'));
        }
    }
};
