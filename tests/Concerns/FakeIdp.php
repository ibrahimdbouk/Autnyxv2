<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Http;

/**
 * A throwaway OIDC identity provider for tests: an RSA key pair, a JWKS
 * document, and RS256-signed ID tokens (WP2.1 — tokens are really verified).
 */
trait FakeIdp
{
    private $idpKey = null;

    protected function idpKey()
    {
        return $this->idpKey ??= openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    }

    protected function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    protected function idpJwks(string $kid = 'k1'): array
    {
        $d = openssl_pkey_get_details($this->idpKey());

        return ['keys' => [[
            'kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => $kid,
            'n' => $this->b64url($d['rsa']['n']), 'e' => $this->b64url($d['rsa']['e']),
        ]]];
    }

    protected function signedIdToken(array $claims, string $kid = 'k1', $key = null, string $alg = 'RS256'): string
    {
        $h = $this->b64url(json_encode(['alg' => $alg, 'typ' => 'JWT', 'kid' => $kid]));
        $p = $this->b64url(json_encode($claims));
        openssl_sign("$h.$p", $sig, $key ?? $this->idpKey(), OPENSSL_ALGO_SHA256);

        return "$h.$p." . $this->b64url($sig);
    }

    protected function fakeIdpEndpoints(string $issuer = 'https://idp.test', array $extra = []): void
    {
        Http::fake(array_merge([
            "{$issuer}/jwks" => Http::response($this->idpJwks()),
        ], $extra));
    }
}
