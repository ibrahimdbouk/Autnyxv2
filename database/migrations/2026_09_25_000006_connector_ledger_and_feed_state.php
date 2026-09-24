<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WP3.7 (audit H29) — connector state.
 *  • sftp_ingested_files: a file is identified by path + size + modification
 *    time (a daily file overwritten at the same path is new data), is only
 *    taken once it has stopped changing ("pending" first sighting), and a
 *    failed file is retried with backoff (attempts, next_attempt_at).
 *  • api_feeds: high-water mark / OData delta link for incremental pulls, an
 *    optional path of nested line items to split into rows, and the last
 *    run's warning (a page or row cap was hit).
 * Small tables; no locking concerns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sftp_ingested_files', function (Blueprint $t) {
            if (! Schema::hasColumn('sftp_ingested_files', 'remote_mtime')) {
                $t->unsignedBigInteger('remote_mtime')->nullable();
            }
            if (! Schema::hasColumn('sftp_ingested_files', 'attempts')) {
                $t->unsignedSmallInteger('attempts')->default(0);
            }
            if (! Schema::hasColumn('sftp_ingested_files', 'next_attempt_at')) {
                $t->timestamp('next_attempt_at')->nullable();
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE sftp_ingested_files DROP CONSTRAINT IF EXISTS sftp_file_unique');
            DB::statement('DROP INDEX IF EXISTS sftp_file_unique');
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS sftp_file_identity ON sftp_ingested_files (sftp_connection_id, remote_path, size_bytes, remote_mtime) NULLS NOT DISTINCT');
        }

        Schema::table('api_feeds', function (Blueprint $t) {
            foreach ([
                'high_water_mark' => fn () => $t->string('high_water_mark')->nullable(),
                'hwm_field'       => fn () => $t->string('hwm_field')->nullable(),
                'delta_link'      => fn () => $t->text('delta_link')->nullable(),
                'split_path'      => fn () => $t->string('split_path')->nullable(),
                'last_warning'    => fn () => $t->text('last_warning')->nullable(),
            ] as $col => $add) {
                if (! Schema::hasColumn('api_feeds', $col)) {
                    $add();
                }
            }
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS sftp_file_identity');
        }
        Schema::table('sftp_ingested_files', fn (Blueprint $t) => $t->dropColumn(['remote_mtime', 'attempts', 'next_attempt_at']));
        Schema::table('api_feeds', fn (Blueprint $t) => $t->dropColumn(['high_water_mark', 'hwm_field', 'delta_link', 'split_path', 'last_warning']));
    }
};
