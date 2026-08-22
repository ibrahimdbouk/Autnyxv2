<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M14 — idempotency ledger for SFTP ingestion. One row per remote file that has
 * been seen, so the poller never re-imports the same file (matched by connection
 * + full remote path).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sftp_ingested_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sftp_connection_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('sftp_feed_id')->nullable();

            $table->string('remote_path', 1024); // full remote path
            $table->string('filename');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('checksum')->nullable();

            $table->unsignedBigInteger('import_id')->nullable();
            $table->string('status')->default('imported'); // imported | failed
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            // A given remote file is ingested once per connection.
            $table->unique(['sftp_connection_id', 'remote_path'], 'sftp_file_unique');
            $table->index(['tenant_id', 'processed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sftp_ingested_files');
    }
};
