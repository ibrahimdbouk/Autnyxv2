<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2b — lightweight subscription state on tenants so the super-admin control
 * plane can show and manage who is subscribed. Full billing (Stripe, M13) stays
 * deferred; this is just the plan label + active/suspended status the /ops
 * dashboard reads. Existing tenants default to an active trial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('plan')->default('trial')->after('slug');   // trial | standard | enterprise
            $table->string('status')->default('active')->after('plan'); // active | suspended
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['plan', 'status']);
        });
    }
};
