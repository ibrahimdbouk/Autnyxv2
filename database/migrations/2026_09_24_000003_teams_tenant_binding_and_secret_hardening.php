<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP2.2 (audit H12 / M7, SFTP host key):
 *  • teams_connections.aad_verified_at — the Microsoft 365 tenant is bound only
 *    through a signed Microsoft admin sign-in (tid claim), never typed in.
 *  • sftp_connections.host_key_fingerprint — trust-on-first-use pin of the
 *    server's host key, so a man-in-the-middle cannot harvest credentials.
 * (outbound_targets.config → encrypted:array is a cast change + the
 *  outbound:encrypt-secrets command, not a schema change.)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('teams_connections') && ! Schema::hasColumn('teams_connections', 'aad_verified_at')) {
            Schema::table('teams_connections', fn (Blueprint $t) => $t->timestamp('aad_verified_at')->nullable());
        }
        if (Schema::hasTable('teams_connections') && \Illuminate\Support\Facades\DB::getDriverName() === 'pgsql') {
            // Set after the admin-consent sign-in, so a new connection starts without it.
            \Illuminate\Support\Facades\DB::statement('ALTER TABLE teams_connections ALTER COLUMN aad_tenant_id DROP NOT NULL');
        }
        if (Schema::hasTable('sftp_connections') && ! Schema::hasColumn('sftp_connections', 'host_key_fingerprint')) {
            Schema::table('sftp_connections', fn (Blueprint $t) => $t->string('host_key_fingerprint', 128)->nullable());
        }
        if (Schema::hasTable('outbound_targets') && \Illuminate\Support\Facades\DB::getDriverName() === 'pgsql') {
            // encrypted:array stores ciphertext (a base64 string, not JSON) — the
            // column must be text. Existing plaintext JSON is kept as text and
            // encrypted by `outbound:encrypt-secrets`.
            \Illuminate\Support\Facades\DB::statement('ALTER TABLE outbound_targets ALTER COLUMN config TYPE text USING config::text');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('teams_connections') && Schema::hasColumn('teams_connections', 'aad_verified_at')) {
            Schema::table('teams_connections', fn (Blueprint $t) => $t->dropColumn('aad_verified_at'));
        }
        if (Schema::hasTable('sftp_connections') && Schema::hasColumn('sftp_connections', 'host_key_fingerprint')) {
            Schema::table('sftp_connections', fn (Blueprint $t) => $t->dropColumn('host_key_fingerprint'));
        }
    }
};
