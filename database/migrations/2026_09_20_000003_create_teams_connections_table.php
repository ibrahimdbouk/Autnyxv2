<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Microsoft Teams notification channel — a tenant's Teams delivery target.
 *
 * The Autnyx Entra app (client id/secret) is global config, not stored here.
 * This row holds only the customer's identifiers + which deliveries are on.
 * See claude/teams-notifications.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('name')->nullable();
            $table->string('aad_tenant_id');                 // customer Azure AD tenant GUID
            $table->string('team_id')->nullable();           // Teams team GUID (channel post via Graph)
            $table->string('channel_id')->nullable();        // channel GUID
            $table->string('teams_app_id')->nullable();      // installed Autnyx Teams app id (activity feed)
            $table->text('channel_webhook_url')->nullable(); // encrypted; preferred channel-post path

            $table->boolean('post_to_channel')->default(true);
            $table->boolean('notify_users')->default(true);
            $table->boolean('is_active')->default(true);

            $table->string('status')->default('never');      // never | ok | error
            $table->timestamp('last_success_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teams_connections');
    }
};
