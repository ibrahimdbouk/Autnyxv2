<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WP2.1 (audit C5, M8, M1):
 *  • sso_connections.verified_domains / domain_verification_token — an email
 *    domain can only sign in through SSO after the tenant proves it owns it
 *    (DNS TXT), and a domain can be verified by one connection only.
 *  • users.is_owner — platform ownership is an immutable flag, no longer
 *    derived from a (changeable) email address. Initialised from the
 *    configured owner email; a new column, so this is not a data repair.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sso_connections')) {
            Schema::table('sso_connections', function (Blueprint $t) {
                if (! Schema::hasColumn('sso_connections', 'verified_domains')) {
                    $t->json('verified_domains')->nullable();
                }
                if (! Schema::hasColumn('sso_connections', 'domain_verification_token')) {
                    $t->string('domain_verification_token', 64)->nullable();
                }
            });
        }

        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'is_owner')) {
            Schema::table('users', function (Blueprint $t) {
                $t->boolean('is_owner')->default(false);
            });

            $ownerEmail = strtolower(trim((string) config('autnyx.owner_email')));
            if ($ownerEmail !== '') {
                DB::table('users')->whereRaw('LOWER(email) = ?', [$ownerEmail])->update(['is_owner' => true]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'is_owner')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('is_owner'));
        }
        if (Schema::hasTable('sso_connections')) {
            Schema::table('sso_connections', function (Blueprint $t) {
                foreach (['verified_domains', 'domain_verification_token'] as $c) {
                    if (Schema::hasColumn('sso_connections', $c)) {
                        $t->dropColumn($c);
                    }
                }
            });
        }
    }
};
