<?php

namespace Tests\Feature;

use App\Models\SsoConnection;
use App\Models\Tenant;
use App\Services\Sso\OidcService;
use RuntimeException;
use Tests\TestCase;

/**
 * 1b — the self-contained OIDC client: authorization URL, ID-token claim
 * decoding, and validation of the security-critical claims.
 */
class OidcServiceTest extends TestCase
{
    private OidcService $oidc;
    private SsoConnection $conn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->oidc = new OidcService();
        $tenant = $this->createTenant();
        $this->conn = SsoConnection::create([
            'tenant_id'              => $tenant->id,
            'enabled'                => true,
            'issuer'                 => 'https://idp.test',
            'authorization_endpoint' => 'https://idp.test/authorize',
            'token_endpoint'         => 'https://idp.test/token',
            'client_id'              => 'client123',
            'client_secret'          => 'shh',
            'scopes'                 => 'openid email profile',
        ]);
    }

    private function jwt(array $claims): string
    {
        $enc = fn (array $x) => rtrim(strtr(base64_encode(json_encode($x)), '+/', '-_'), '=');

        return $enc(['alg' => 'RS256', 'typ' => 'JWT']) . '.' . $enc($claims) . '.sig';
    }

    public function test_authorization_url_carries_the_flow_parameters(): void
    {
        $url = $this->oidc->authorizationUrl($this->conn, 'https://app.test/cb', 'STATE1', 'NONCE1');

        $this->assertStringStartsWith('https://idp.test/authorize?', $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString('client_id=client123', $url);
        $this->assertStringContainsString('redirect_uri=' . urlencode('https://app.test/cb'), $url);
        $this->assertStringContainsString('state=STATE1', $url);
        $this->assertStringContainsString('nonce=NONCE1', $url);
        $this->assertStringContainsString('scope=' . urlencode('openid email profile'), $url);
    }

    public function test_claims_from_id_token_decodes_payload(): void
    {
        $token = $this->jwt(['sub' => 'u1', 'email' => 'a@idp.test', 'nonce' => 'N']);
        $claims = $this->oidc->claimsFromIdToken($token);

        $this->assertSame('u1', $claims['sub']);
        $this->assertSame('a@idp.test', $claims['email']);
    }

    public function test_validate_passes_for_good_claims(): void
    {
        $claims = ['iss' => 'https://idp.test', 'aud' => 'client123', 'exp' => time() + 600, 'nonce' => 'N'];
        $this->oidc->validateIdToken($claims, $this->conn, 'N');
        $this->assertTrue(true); // no exception
    }

    public function test_validate_rejects_bad_issuer(): void
    {
        $this->expectException(RuntimeException::class);
        $this->oidc->validateIdToken(
            ['iss' => 'https://evil.test', 'aud' => 'client123', 'exp' => time() + 600, 'nonce' => 'N'],
            $this->conn,
            'N'
        );
    }

    public function test_validate_rejects_wrong_audience(): void
    {
        $this->expectException(RuntimeException::class);
        $this->oidc->validateIdToken(
            ['iss' => 'https://idp.test', 'aud' => 'someone-else', 'exp' => time() + 600, 'nonce' => 'N'],
            $this->conn,
            'N'
        );
    }

    public function test_validate_rejects_expired_token(): void
    {
        $this->expectException(RuntimeException::class);
        $this->oidc->validateIdToken(
            ['iss' => 'https://idp.test', 'aud' => 'client123', 'exp' => time() - 3600, 'nonce' => 'N'],
            $this->conn,
            'N'
        );
    }

    public function test_validate_rejects_nonce_mismatch(): void
    {
        $this->expectException(RuntimeException::class);
        $this->oidc->validateIdToken(
            ['iss' => 'https://idp.test', 'aud' => 'client123', 'exp' => time() + 600, 'nonce' => 'WRONG'],
            $this->conn,
            'N'
        );
    }

    public function test_client_secret_is_encrypted_at_rest(): void
    {
        $raw = \Illuminate\Support\Facades\DB::table('sso_connections')->where('id', $this->conn->id)->value('client_secret');
        $this->assertNotSame('shh', $raw, 'secret must not be stored in plaintext');
        $this->assertSame('shh', $this->conn->fresh()->client_secret, 'and must decrypt back');
    }
}
