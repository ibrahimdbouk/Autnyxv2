<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SsoConnection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 1b — the OIDC callback end to end, with a faked IdP token endpoint. Covers the
 * happy path (validate → provision → sign in → audit) and the state-mismatch
 * rejection.
 */
class SsoCallbackTest extends TestCase
{
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
            'client_id'              => 'client123',
            'client_secret'          => 'secret',
            'jit_provisioning'       => true,
            'allowed_domains'        => ['acme.com'],
        ]);
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

        $idToken = $this->jwt([
            'iss'   => 'https://idp.test',
            'aud'   => 'client123',
            'exp'   => time() + 600,
            'nonce' => $nonce,
            'email' => 'sso@acme.com',
            'name'  => 'SSO User',
        ]);

        Http::fake([
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
}
