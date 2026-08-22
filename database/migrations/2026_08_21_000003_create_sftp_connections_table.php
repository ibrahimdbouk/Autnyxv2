<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M14 — SFTP / flat-file integration.
 *
 * A tenant's connection to a remote SFTP server from which flat files are pulled
 * automatically and run through the existing import pipeline. Credentials are
 * stored encrypted (model-level encrypted casts).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sftp_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('host');
            $table->unsignedInteger('port')->default(22);
            $table->string('username');

            $table->string('auth_type')->default('password'); // password | key
            $table->text('password')->nullable();             // encrypted
            $table->text('private_key')->nullable();          // encrypted
            $table->text('private_key_passphrase')->nullable();// encrypted

            $table->string('base_path')->nullable();          // remote root, e.g. /uploads
            $table->boolean('is_active')->default(true);

            // Poll status
            $table->string('status')->default('never'); // never | ok | error
            $table->timestamp('last_polled_at')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sftp_connections');
    }
};
