<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP3.2 (audit C7, H26) — how a file is read is decided once and stored:
 *  • imports: date order + decimal separator (override of the tenant default,
 *    D4) and the detected CSV delimiter + encoding, used on every read path.
 *  • sftp_feeds: per-feed date order + decimal separator, copied onto each
 *    import the feed creates.
 * Tenant defaults live in tenants.settings (import_date_format,
 * import_decimal_separator); d/m/Y and "." when unset. Small tables, nullable
 * columns: no rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imports', function (Blueprint $t) {
            if (! Schema::hasColumn('imports', 'date_format')) {
                $t->string('date_format', 10)->nullable();
            }
            if (! Schema::hasColumn('imports', 'decimal_separator')) {
                $t->string('decimal_separator', 1)->nullable();
            }
            if (! Schema::hasColumn('imports', 'delimiter')) {
                $t->string('delimiter', 1)->nullable();
            }
            if (! Schema::hasColumn('imports', 'encoding')) {
                $t->string('encoding', 20)->nullable();
            }
        });

        Schema::table('sftp_feeds', function (Blueprint $t) {
            if (! Schema::hasColumn('sftp_feeds', 'date_format')) {
                $t->string('date_format', 10)->nullable();
            }
            if (! Schema::hasColumn('sftp_feeds', 'decimal_separator')) {
                $t->string('decimal_separator', 1)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('imports', fn (Blueprint $t) => $t->dropColumn(['date_format', 'decimal_separator', 'delimiter', 'encoding']));
        Schema::table('sftp_feeds', fn (Blueprint $t) => $t->dropColumn(['date_format', 'decimal_separator']));
    }
};
