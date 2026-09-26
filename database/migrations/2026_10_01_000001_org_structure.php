<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform core — organisation structure, shared by every app (Root Cause,
 * Task Execution, later Assortment), so turning an app on needs no new setup:
 *
 *   • stores.area + stores.geofence_radius_m — the tree is region → area →
 *     store (built by HierarchySync from the store fields), and a store has a
 *     radius within which on-site work counts as on site;
 *   • location_node_managers — who manages a region or an area (the
 *     escalation chain above the store manager);
 *   • departments — the tenant's department → section catalogue;
 *   • store_zones — places inside a store (aisle, endcap, chiller…);
 *   • users.org_role / locale / deactivated_at — the operating role ladder
 *     (separate from admin rights), language, and leavers;
 *   • user_devices — phones registered for push (the mobile app).
 *
 * None of these tables is hot (see docs/migrations.md); all additive. The
 * case-insensitive unique names are built in the next migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $t) {
            $t->string('area')->nullable();
            $t->unsignedSmallInteger('geofence_radius_m')->nullable();
        });

        Schema::create('location_node_managers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('location_node_id')->constrained('location_nodes')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestamps();

            $t->unique(['location_node_id', 'user_id']);
            $t->index(['tenant_id', 'user_id']);
        });

        Schema::create('departments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('parent_id')->nullable()->constrained('departments')->cascadeOnDelete();
            $t->string('code', 40)->nullable();
            $t->string('name', 120);
            $t->json('categories')->nullable();   // product departments / categories it covers
            $t->unsignedSmallInteger('sort')->default(0);
            $t->boolean('active')->default(true);
            $t->string('source', 20)->default('manual'); // manual | products
            $t->timestamps();

            $t->index(['tenant_id', 'parent_id']);
        });

        Schema::create('store_zones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $t->string('code', 40)->nullable();
            $t->string('name', 120);
            $t->string('zone_type', 30)->default('aisle');
            $t->unsignedSmallInteger('sort')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();

            $t->index(['tenant_id', 'store_id']);
            $t->index(['tenant_id', 'department_id']);
        });

        Schema::table('users', function (Blueprint $t) {
            $t->string('org_role', 20)->nullable();     // hq | area_manager | store_manager | associate
            $t->string('locale', 5)->nullable();        // en | ar | ur | hi
            $t->timestamp('deactivated_at')->nullable();
        });

        Schema::create('user_devices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('platform', 10);                 // ios | android | web
            $t->char('token_hash', 64)->unique();       // sha256 of the push token (lookup)
            $t->text('token');                          // encrypted push token
            $t->string('device_name', 120)->nullable();
            $t->string('app_version', 30)->nullable();
            $t->string('locale', 5)->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->string('revoked_reason', 40)->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_devices');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['org_role', 'locale', 'deactivated_at']));
        Schema::dropIfExists('store_zones');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('location_node_managers');
        Schema::table('stores', fn (Blueprint $t) => $t->dropColumn(['area', 'geofence_radius_m']));
    }
};
