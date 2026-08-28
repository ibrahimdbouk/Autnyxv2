<?php

namespace App\Services\Sso;

use App\Models\SsoConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * 1b — a self-contained OIDC authorization-code client.
 *
 * Deliberately dependency-free (Laravel's HTTP client only) because the build
 * pipeline can't add Composer packages. It implements the confidential-client
 * authorization-code flow: discover endpoints → redirect to the IdP → exchange
 * the code for tokens over a TLS back-channel → read the ID-token claims.
 *
 * ID-token handling: the token is obtained directly from the IdP's token
 * endpoint over TLS with client authentication, so per OIDC Core §3.1.3.7 the
 * client MAY rely on the transport channel rather than re-verifying the JWT
 * signature. We still validate the security-critical claims (iss, aud, exp,
 * nonce). Full JWKS/RS256 signature verification is a documented hardening
 * follow-up, not a correctness gap for this flow.
 */
class OidcService
{
    private const DISCOVERY_TTL = 3600; // seconds
    private const LEEWAY = 60;           // clock-skew allowance for exp (seconds)

    /**
     * Resolve the IdP endpoints — explicit column overrides win, otherwise the
     * issuer's cached discovery document.
     *
     * @return array{authorization_endpoint:string,token_endpoint:string,userinfo_endpoint:?string,jwks_uri:?string}
     */
    public function endpoints(SsoConnection $conn): array
    {
        $auth  = $conn->authorization_endpoint;
        $token = $conn->token_endpoint;
        $info  = $conn->userinfo_endpoint;
        $jwks  = $conn->jwks_uri;

        if (empty($auth) || empty($token)) {
            $doc   = $this->discovery($conn);
            $auth  = $auth  ?: ($doc['authorization_endpoint'] ?? null);
            $token = $token ?: ($doc['token_endpoint'] ?? null);
            $info  = $info  ?: ($doc['userinfo_endpoint'] ?? null);
            $jwks  = $jwks  ?: ($doc['jwks_uri'] ?? null);
        }

        if (empty($auth) || empty($token)) {
            throw new RuntimeException('OIDC endpoints could not be resolved for this connection.');
        }

        return [
            'authorization_endpoint' => $auth,
            'token_endpoint'         => $token,
            'userinfo_endpoint'      => $info,
            'jwks_uri'               => $jwks,
        ];
    }

    /** Fetch (and cache) the issuer's discovery document. */
    public function discovery(SsoConnection $conn): array
    {
        $url = $conn->discoveryUrl();

        return Cache::remember('oidc_discovery_' . md5($url), self::DISCOVERY_TTL, function () use ($url) {
            $resp = Http::acceptJson()->timeout(10)->get($url);
            if (! $resp->successful()) {
                throw new RuntimeException("OIDC discovery failed ({$resp->status()}) for {$url}.");
            }

            return (array) $resp->json();
        });
    }

    /**
     * Build the authorization-request URL the browser is redirected to.
     */
    public function authorizationUrl(SsoConnection $conn, string $redirectUri, string $state, string $nonce): string
    {
        $endpoint = $this->endpoints($conn)['authorization_endpoint'];

        $params = [
            'response_type' => 'code',
            'client_id'     => $conn->client_id,
            'redirect_uri'  => $redirectUri,
            'scope'         => implode(' ', $conn->scopeList()),
            'state'         => $state,
            'nonce'         => $nonce,
        ];

        $sep = str_contains($endpoint, '?') ? '&' : '?';

        return $endpoint . $sep . http_build_query($params);
    }

    /**
     * Exchange the authorization code for tokens (back-channel, TLS).
     *
     * @return array{access_token?:string,id_token?:string,token_type?:string,expires_in?:int}
     */
    public function exchangeCode(SsoConnection $conn, string $code, string $redirectUri): array
    {
        $endpoint = $this->endpoints($conn)['token_endpoint'];

        $resp = Http::asForm()->acceptJson()->timeout(10)->post($endpoint, [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'client_id'     => $conn->client_id,
            'client_secret' => (string) $conn->client_secret,
        ]);

        if (! $resp->successful()) {
            throw new RuntimeException("OIDC token exchange failed ({$resp->status()}).");
        }

        $data = (array) $resp->json();
        if (empty($data['id_token'])) {
            throw new RuntimeException('OIDC token response did not include an id_token.');
        }

        return $data;
    }

    /**
     * Decode the ID-token payload claims (no signature check — see class note).
     *
     * @return array<string,mixed>
     */
    public function claimsFromIdToken(string $idToken): array
    {
        $parts = explode('.', $idToken);
        if (count($parts) < 2) {
            throw new RuntimeException('Malformed ID token.');
        }

        $payload = $this->base64UrlDecode($parts[1]);
        $claims  = json_decode($payload, true);

        if (! is_array($claims)) {
            throw new RuntimeException('ID token payload is not valid JSON.');
        }

        return $claims;
    }

    /**
     * Validate the security-critical ID-token claims for this connection.
     *
     * @param  array<string,mixed>  $claims
     */
    public function validateIdToken(array $claims, SsoConnection $conn, string $expectedNonce): void
    {
        // Issuer — tolerate a trailing-slash difference.
        $iss = rtrim((string) ($claims['iss'] ?? ''), '/');
        if ($iss === '' || $iss !== rtrim($conn->issuer, '/')) {
            throw new RuntimeException('ID token issuer mismatch.');
        }

        // Audience — string or array, must contain our client_id.
        $aud = $claims['aud'] ?? null;
        $audOk = is_array($aud) ? in_array($conn->client_id, $aud, true) : ($aud === $conn->client_id);
        if (! $audOk) {
            throw new RuntimeException('ID token audience mismatch.');
        }

        // Expiry.
        $exp = isset($claims['exp']) ? (int) $claims['exp'] : 0;
        if ($exp <= 0 || $exp + self::LEEWAY < time()) {
            throw new RuntimeException('ID token has expired.');
        }

        // Nonce — replay protection tied to the session that started the flow.
        if (! isset($claims['nonce']) || ! hash_equals($expectedNonce, (string) $claims['nonce'])) {
            throw new RuntimeException('ID token nonce mismatch.');
        }
    }

    /**
     * Optionally enrich claims from the userinfo endpoint.
     *
     * @return array<string,mixed>
     */
    public function userInfo(SsoConnection $conn, string $accessToken): array
    {
        $endpoint = $this->endpoints($conn)['userinfo_endpoint'] ?? null;
        if (empty($endpoint)) {
            return [];
        }

        $resp = Http::withToken($accessToken)->acceptJson()->timeout(10)->get($endpoint);

        return $resp->successful() ? (array) $resp->json() : [];
    }

    private function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        if ($decoded === false) {
            throw new RuntimeException('ID token segment is not valid base64url.');
        }

        return $decoded;
    }
}
