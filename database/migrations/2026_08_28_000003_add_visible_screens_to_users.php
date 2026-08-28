<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1a — per-user screen visibility.
 *
 * `visible_screens` is a JSON array of screen keys (see App\Support\Screens\
 * ScreenRegistry) a non-admin user is allowed to see. Semantics:
 *   • admins (super / tenant) ignore this entirely — they see everything.
 *   • NULL  → unrestricted (see all gate-able screens). Existing users keep
 *             null, so this migration changes no one's access.
 *   • array → the user sees exactly those screens (empty = only the always-on
 *             Dashboard).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('visible_screens')->nullable()->after('is_tenant_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('visible_screens');
        });
    }
};
