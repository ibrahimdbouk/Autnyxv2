<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maps an Autnyx user to their Azure AD object id so the Teams channel can send
 * per-user activity-feed notifications. Nullable — a user with no mapping simply
 * doesn't receive Teams pings (they still get in-app + email). Populated by an
 * admin, or later auto-resolved by matching userPrincipalName to email via Graph.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('teams_aad_user_id')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('teams_aad_user_id');
        });
    }
};
