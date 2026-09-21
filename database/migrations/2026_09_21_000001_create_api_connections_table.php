<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API integration — a tenant's connection to a source system's REST/OData API,
 * from which retail data is pulled and run through the standard import pipeline
 * (the API-based sibling of sftp_connections). Auth secrets encrypted at rest.
 * See claude/api-integration-library.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('provider')->default('generic_rest'); // generic_rest | sap_s4hana | dynamics365 | oracle | shopify | blue_yonder | relex | slimstock
            $table->string('base_url');
            $table->string('auth_type')->default('none');        // none | bearer | basic | api_key_header | oauth2_client_credentials
            $table->text('auth_config')->nullable();             // encrypted JSON

            $table->boolean('is_active')->default(true);

            $table->string('status')->default('never');          // never | ok | error
            $table->timestamp('last_polled_at')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('api_feeds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('data_type');                         // Import::TYPE_* (sales_transactions, inventory_levels, …)
            $table->string('endpoint');                          // relative path appended to base_url
            $table->string('records_path')->nullable();          // dot-path to the array in the response (value | d.results | orders)
            $table->json('field_map')->nullable();               // canonical header => API field / dot-path
            $table->json('params')->nullable();                  // extra static query params
            $table->string('page_strategy')->default('none');    // none | page | offset | odata_skiptop | next_link | link_header
            $table->unsignedInteger('page_size')->default(100);
            $table->boolean('enabled')->default(true);

            $table->timestamps();

            $table->index(['api_connection_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_feeds');
        Schema::dropIfExists('api_connections');
    }
};
