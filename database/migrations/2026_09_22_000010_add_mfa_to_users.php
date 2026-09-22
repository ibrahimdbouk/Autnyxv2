<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3b — admin MFA/2FA. Adds the two columns Filament v5's app-authenticator
 * (TOTP) + recovery-code flow persists into. Both are stored ENCRYPTED at rest
 * (the User model casts them `encrypted` / `encrypted:array`), so a DB leak does
 * not expose the TOTP secret or the recovery codes. Nullable — MFA is opt-in per
 * user; a null secret means "not enrolled". See claude/security-compliance-notes.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('app_authentication_secret')->nullable()->after('password');
            $table->text('app_authentication_recovery_codes')->nullable()->after('app_authentication_secret');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['app_authentication_secret', 'app_authentication_recovery_codes']);
        });
    }
};
