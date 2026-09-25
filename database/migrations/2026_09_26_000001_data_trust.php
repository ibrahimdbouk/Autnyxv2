<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * W9 — data trust.
 *
 *  - imports.source / source_ref / feed_key: every batch belongs to a feed
 *    (upload, sftp:<feed>, api:<feed>, ingest-api).
 *  - data_contracts becomes the feed registry: what each feed normally
 *    delivers (cadence, row band, header signature), learned from its
 *    history, plus an optional owner to alert. Explicit contracts still win.
 *  - dq_findings: semantic and cross-dataset data-quality findings (one row
 *    per check and subject, re-seen or resolved on every run).
 *  - import_quality.pii_columns: columns that looked like personal data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imports', function (Blueprint $t) {
            $t->string('source', 20)->nullable();
            $t->string('source_ref', 64)->nullable();
            $t->string('feed_key', 120)->nullable();
        });

        Schema::table('data_contracts', function (Blueprint $t) {
            $t->string('data_type', 40)->nullable();
            $t->string('source', 40)->nullable();
            $t->boolean('learned')->default(false);
            $t->unsignedInteger('expected_every_hours')->nullable();
            $t->unsignedInteger('rows_median')->nullable();
            $t->unsignedInteger('rows_low')->nullable();
            $t->unsignedInteger('rows_high')->nullable();
            $t->unsignedInteger('batches_seen')->default(0);
            $t->timestamp('last_batch_at')->nullable();
            $t->unsignedBigInteger('last_import_id')->nullable();
            $t->unsignedInteger('last_rows')->nullable();
            $t->string('header_signature', 64)->nullable();
            $t->json('header_columns')->nullable();
            $t->string('owner_email')->nullable();
            $t->string('status', 20)->default('ok');
            $t->string('status_detail')->nullable();
            $t->timestamp('status_at')->nullable();
        });

        Schema::table('contract_violations', function (Blueprint $t) {
            $t->unsignedBigInteger('import_id')->nullable();
        });

        Schema::create('dq_findings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('check', 60);
            $t->string('dataset', 40);
            $t->string('severity', 10);
            $t->string('subject_key', 191)->default('');
            $t->string('subject')->nullable();
            $t->text('message');
            $t->jsonb('metrics')->nullable();
            $t->unsignedInteger('occurrences')->default(1);
            $t->timestamp('first_seen_at');
            $t->timestamp('last_seen_at');
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();

            $t->unique(['tenant_id', 'check', 'subject_key']);
            $t->index(['tenant_id', 'resolved_at', 'severity']);
        });

        Schema::table('import_quality', function (Blueprint $t) {
            $t->json('pii_columns')->nullable();
        });

        // Backfill which feed earlier batches came from, so cadence and volume
        // are learned from history rather than from scratch.
        DB::statement("UPDATE imports i SET source = 'sftp', source_ref = f.sftp_feed_id::text,
                feed_key = 'sftp:' || COALESCE(f.sftp_feed_id::text || ':', '') || i.data_type
            FROM sftp_ingested_files f WHERE f.import_id = i.id AND i.source IS NULL");
        DB::statement("UPDATE imports SET source = 'ingest', feed_key = 'ingest:' || data_type WHERE source IS NULL AND path LIKE 'apiingest-imports/%'");
        DB::statement("UPDATE imports SET source = 'api', feed_key = 'api:' || data_type WHERE source IS NULL AND path LIKE 'api-imports/%'");
        DB::statement("UPDATE imports SET source = 'sftp', feed_key = 'sftp:' || data_type WHERE source IS NULL AND path LIKE 'sftp-imports/%'");
        DB::statement("UPDATE imports SET source = 'upload', feed_key = 'upload:' || data_type WHERE source IS NULL");
    }

    public function down(): void
    {
        Schema::table('import_quality', fn (Blueprint $t) => $t->dropColumn('pii_columns'));
        Schema::dropIfExists('dq_findings');
        Schema::table('contract_violations', fn (Blueprint $t) => $t->dropColumn('import_id'));
        Schema::table('data_contracts', fn (Blueprint $t) => $t->dropColumn([
            'data_type', 'source', 'learned', 'expected_every_hours', 'rows_median', 'rows_low', 'rows_high', 'batches_seen',
            'last_batch_at', 'last_import_id', 'last_rows', 'header_signature', 'header_columns', 'owner_email', 'status', 'status_detail', 'status_at',
        ]));
        Schema::table('imports', fn (Blueprint $t) => $t->dropColumn(['source', 'source_ref', 'feed_key']));
    }
};
