<?php

use App\Support\Database\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;

/**
 * Platform core — a department name is unique at its level, and a zone name
 * within its store, ignoring case (the forms and imports match them that
 * way). Built CONCURRENTLY with a lock timeout — see docs/migrations.md.
 */
return new class extends Migration
{
    // CREATE INDEX CONCURRENTLY cannot run inside a transaction.
    public $withinTransaction = false;

    public function up(): void
    {
        ConcurrentIndex::create('departments_tenant_parent_name_unique', 'departments', '(tenant_id, COALESCE(parent_id, 0), lower(name))', unique: true);
        ConcurrentIndex::create('store_zones_store_name_unique', 'store_zones', '(store_id, lower(name))', unique: true);
    }

    public function down(): void
    {
        ConcurrentIndex::drop('store_zones_store_name_unique');
        ConcurrentIndex::drop('departments_tenant_parent_name_unique');
    }
};
