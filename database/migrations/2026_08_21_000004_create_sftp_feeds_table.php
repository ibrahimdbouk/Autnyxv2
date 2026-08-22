<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M14 — a feed maps a remote directory + filename pattern on an SFTP connection
 * to one of the import data types. One connection can carry many feeds
 * (e.g. /sales/*.csv → sales_transactions, /inv/*.xlsx → inventory_levels).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sftp_feeds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sftp_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Import data type: sales_transactions | inventory_levels | products |
            // purchase_orders | stores | suppliers | users
            $table->string('data_type');

            $table->string('remote_path')->default('.');       // directory under base_path
            $table->string('filename_pattern')->default('*.csv'); // glob pattern
            $table->string('archive_path')->nullable();         // move processed files here
            $table->boolean('delete_after')->default(false);    // delete processed remote file
            $table->boolean('enabled')->default(true);

            $table->timestamps();

            $table->index(['sftp_connection_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sftp_feeds');
    }
};
