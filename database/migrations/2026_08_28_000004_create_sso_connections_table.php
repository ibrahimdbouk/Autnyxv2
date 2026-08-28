<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1b — per-tenant OIDC single sign-on configuration.
 *
 * One connection per tenant (unique tenant_id). The IdP issuer drives discovery
 * of the authorization / token / userinfo endpoints; those columns are optional
 * overrides for IdPs without a discovery document. `client_secret` is stored
 * encrypted (APP_KEY) via the model cast. Password login stays available as an
 * admin break-glass path — SSO never removes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();

            $table->boolean('enabled')->default(false);
            $table->string('label')->default('Single sign-on');   // e.g. "Okta", "Azure AD"

            // OIDC endpoints — issuer drives discovery; the rest are overrides.
            $table->string('issuer');
            $table->string('discovery_url')->nullable();
            $table->string('authorization_endpoint')->nullable();
            $table->string('token_endpoint')->nullable();
            $table->string('userinfo_endpoint')->nullable();
            $table->string('jwks_uri')->nullable();

            // Client credentials (confidential client).
            $table->string('client_id');
            $table->text('client_secret')->nullable();            // encrypted at rest
            $table->string('scopes')->default('openid email profile');

            // Claim mapping + provisioning.
            $table->string('email_claim')->default('email');
            $table->string('name_claim')->default('name');
            $table->boolean('jit_provisioning')->default(true);
            $table->json('allowed_domains')->nullable();          // e.g. ["acme.com"]
            $table->string('admin_group_claim')->nullable();      // e.g. "groups"
            $table->string('admin_group_value')->nullable();      // membership → tenant admin

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_connections');
    }
};
