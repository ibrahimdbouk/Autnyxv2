<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SsoConnection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakeIdp;
use Tests\TestCase;

/**
 * 1b — the OIDC callback end to end, with a faked IdP token endpoint. Covers the
 * happy path (validate → provision → sign in → audit) and the state-mismatch
 * rejection.
 */
class SsoCallbackTest extends TestCase
{
    use FakeIdp;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant(['slug' => 'acme']);
        SsoConnection::create([
            'tenant_id'              => $this->tenant->id,
            'enabled'                => true,
            'issuer'                 => 'https://idp.test',
            'authorization_endpoint' => 'https://idp.test/authorize',
            'token_endpoint'         => 'https://idp.test/token',
            'jwks_uri'               => 'https://idp.test/jwks',
            'client_id'              => 'client123',
            'client_secret'          => 'secret',
            'jit_provisioning'       => true,
            'allowed_domains'        => ['acme.com'],
        ])->forceFill(['verified_domains' => ['acme.com']])->save(); // never mass-assignable
    }

    private function jwt(array $claims): string
    {
        $enc = fn (array $x) => rtrim(strtr(base64_encode(json_encode($x)), '+/', '-_'), '=');

        return $enc(['alg' => 'RS256', 'typ' => 'JWT']) . '.' . $enc($claims) . '.sig';
    }

    public function test_valid_callback_signs_in_and_provisions(): void
    {
        $nonce = 'nonce-xyz';
        $state = 'state-abc';

        $idToken = $this->signedIdToken([
            'iss'   => 'https://idp.test',
            'aud'   => 'client123',
            'exp'   => time() + 600,
            'nonce' => $nonce,
            'email' => 'sso@acme.com',
            'name'  => 'SSO User',
        ]);

        $this->fakeIdpEndpoints('https://idp.test', [
            'https://idp.test/token' => Http::response(['id_token' => $idToken, 'access_token' => 'a']),
        ]);

        $response = $this->withSession(['sso_state' => $state, 'sso_nonce' => $nonce])
            ->get('/sso/acme/callback?state=' . $state . '&code=authcode');

        $response->assertRedirect('/admin/acme');
        $this->assertAuthenticated();

        $user = User::where('email', 'sso@acme.com')->first();
        $this->assertNotNull($user);
        $this->assertSame($this->tenant->id, $user->tenant_id);

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id'  => $this->tenant->id,
            'event_type' => AuditLog::EVENT_SSO_LOGIN,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id'  => $this->tenant->id,
            'event_type' => AuditLog::EVENT_SSO_PROVISIONED,
        ]);
    }

    public function test_state_mismatch_is_rejected(): void
    {
        $response = $this->withSession(['sso_state' => 'real-state', 'sso_nonce' => 'n'])
            ->get('/sso/acme/callback?state=forged-state&code=authcode');

        $response->assertRedirect(route('sso.start'));
        $this->assertGuest();

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id'  => $this->tenant->id,
            'event_type' => AuditLog::EVENT_SSO_LOGIN_FAILED,
        ]);
    }

    public function test_disabled_connection_offers_no_sso(): void
    {
        $this->tenant->ssoConnection->update(['enabled' => false]);

        $response = $this->get('/sso/acme/redirect');
        $response->assertRedirect(route('sso.start'));
        $this->assertGuest();
    }

    // ── WP2.1 ────────────────────────────────────────────────────────────────

    private function callbackAs(string $email, ?string $idToken = null)
    {
        $idToken ??= $this->signedIdToken([
            'iss' => 'https://idp.test', 'aud' => 'client123', 'exp' => time() + 600,
            'nonce' => 'n1', 'email' => $email, 'name' => 'X',
        ]);
        $this->fakeIdpEndpoints('https://idp.test', [
            'https://idp.test/token' => Http::response(['id_token' => $idToken, 'access_token' => 'a']),
        ]);

        return $this->withSession(['sso_state' => 's1', 'sso_nonce' => 'n1'])
            ->get('/sso/acme/callback?state=s1&code=c');
    }

    public function test_tenant_sso_can_never_sign_in_a_super_admin_or_owner(): void
    {
        auth()->logout();
        $super = User::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'root@acme.com', 'is_super_admin' => true]);
        $owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'owner@acme.com', 'is_owner' => true]);

        $this->callbackAs('root@acme.com')->assertRedirect(route('sso.start'));
        $this->assertGuest();
        $this->callbackAs('owner@acme.com')->assertRedirect(route('sso.start'));
        $this->assertGuest();
    }

    public function test_unverified_domain_cannot_sign_in(): void
    {
        $this->tenant->ssoConnection->forceFill(['allowed_domains' => ['acme.com', 'victim.com'], 'verified_domains' => ['acme.com']])->save();

        $this->callbackAs('ceo@victim.com')->assertRedirect(route('sso.start'));
        $this->assertGuest();
        $this->assertNull(User::where('email', 'ceo@victim.com')->first());
    }

    public function test_forged_token_from_a_rogue_token_endpoint_is_rejected(): void
    {
        $rogue = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $forged = $this->signedIdToken(['iss' => 'https://idp.test', 'aud' => 'client123', 'exp' => time() + 600, 'nonce' => 'n1', 'email' => 'x@acme.com'], 'k1', $rogue);

        $this->callbackAs('x@acme.com', $forged)->assertRedirect(route('sso.start'));
        $this->assertGuest();
    }

    public function test_discovery_only_routes_verified_domains(): void
    {
        $this->tenant->ssoConnection->forceFill(['allowed_domains' => ['acme.com', 'claimed.com'], 'verified_domains' => ['acme.com']])->save();

        $this->post('/sso/start', ['email' => 'a@claimed.com'])->assertRedirect(route('sso.start'));
        $this->post('/sso/start', ['email' => 'a@acme.com'])->assertRedirect(route('sso.redirect', ['tenant' => 'acme']));
    }
}
