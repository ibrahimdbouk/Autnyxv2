<?php

namespace Tests\Feature;

use App\Models\SsoConnection;
use App\Models\Tenant;
use App\Services\Sso\OidcService;
use RuntimeException;
use Tests\Concerns\FakeIdp;
use Tests\TestCase;

/**
 * 1b — the self-contained OIDC client: authorization URL, ID-token claim
 * decoding, and validation of the security-critical claims.
 */
class OidcServiceTest extends TestCase
{
    use FakeIdp;

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
            'jwks_uri'               => 'https://idp.test/jwks',
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
        $this->fakeIdpEndpoints();
        $token = $this->signedIdToken(['sub' => 'u1', 'email' => 'a@idp.test', 'nonce' => 'N']);
        $claims = $this->oidc->claimsFromIdToken($token, $this->conn);

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

    // ── WP2.1 (audit C5): signatures are verified ───────────────────────────

    public function test_unsigned_or_forged_tokens_are_rejected(): void
    {
        $this->fakeIdpEndpoints();
        $claims = ['sub' => 'u1', 'email' => 'boss@idp.test'];

        $forger = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $cases = [
            'no signature'   => $this->jwt($claims),
            'alg none'       => $this->b64url(json_encode(['alg' => 'none'])) . '.' . $this->b64url(json_encode($claims)) . '.',
            'HS256'          => $this->b64url(json_encode(['alg' => 'HS256', 'kid' => 'k1'])) . '.' . $this->b64url(json_encode($claims)) . '.' . $this->b64url(hash_hmac('sha256', 'x', 'shh', true)),
            'wrong key'      => $this->signedIdToken($claims, 'k1', $forger),
            'unknown kid'    => $this->signedIdToken($claims, 'other'),
        ];

        foreach ($cases as $label => $token) {
            try {
                $this->oidc->claimsFromIdToken($token, $this->conn);
                $this->fail("{$label}: a token that is not signed by the IdP was accepted");
            } catch (RuntimeException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_tampered_payload_is_rejected(): void
    {
        $this->fakeIdpEndpoints();
        [$h, , $s] = explode('.', $this->signedIdToken(['email' => 'user@idp.test']));
        $tampered = $h . '.' . $this->b64url(json_encode(['email' => 'owner@idp.test'])) . '.' . $s;

        $this->expectException(RuntimeException::class);
        $this->oidc->claimsFromIdToken($tampered, $this->conn);
    }

    public function test_future_iat_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->oidc->validateIdToken(['iss' => 'https://idp.test', 'aud' => 'client123', 'exp' => time() + 600, 'iat' => time() + 3600, 'nonce' => 'N'], $this->conn, 'N');
    }
}
