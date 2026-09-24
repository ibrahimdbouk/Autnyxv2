<?php

namespace App\Services\Sso;

use RuntimeException;

/**
 * WP2.1 (audit C5) — verify an OIDC ID token's JWS signature against the
 * identity provider's published JSON Web Key Set, dependency-free (OpenSSL).
 *
 * Accepts only asymmetric algorithms an IdP can sign with and we can verify
 * with public keys: RS256/384/512 and ES256/384/512. `none` and HMAC (HS*) are
 * refused outright — an HS token would be "signed" with the client secret,
 * which is not proof the IdP issued it.
 */
class IdTokenVerifier
{
    private const ALGS = [
        'RS256' => ['RSA', OPENSSL_ALGO_SHA256, 0],
        'RS384' => ['RSA', OPENSSL_ALGO_SHA384, 0],
        'RS512' => ['RSA', OPENSSL_ALGO_SHA512, 0],
        'ES256' => ['EC', OPENSSL_ALGO_SHA256, 32],
        'ES384' => ['EC', OPENSSL_ALGO_SHA384, 48],
        'ES512' => ['EC', OPENSSL_ALGO_SHA512, 66],
    ];

    /**
     * @param  callable(bool $refresh): array  $jwksProvider  returns the JWKS document; called
     *         again with $refresh = true once if the token's kid is unknown (key rotation).
     * @return array<string,mixed>  the verified claims
     */
    public function verify(string $jwt, callable $jwksProvider): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3 || $parts[2] === '') {
            throw new RuntimeException('ID token is not a signed JWT.');
        }
        [$h64, $p64, $s64] = $parts;

        $header = json_decode($this->b64($h64), true);
        if (! is_array($header) || ! isset($header['alg'])) {
            throw new RuntimeException('ID token header is invalid.');
        }
        $alg = (string) $header['alg'];
        if (! isset(self::ALGS[$alg])) {
            throw new RuntimeException("ID token algorithm '{$alg}' is not accepted.");
        }
        [$kty, $hash, $coordLen] = self::ALGS[$alg];
        $kid = isset($header['kid']) ? (string) $header['kid'] : null;

        $jwk = $this->findKey($jwksProvider(false), $kty, $kid, $alg)
            ?? $this->findKey($jwksProvider(true), $kty, $kid, $alg);
        if ($jwk === null) {
            throw new RuntimeException('No matching signing key was found in the identity provider\'s key set.');
        }

        $signature = $this->b64($s64);
        if ($kty === 'EC') {
            if (strlen($signature) !== 2 * $coordLen) {
                throw new RuntimeException('ID token EC signature has the wrong length.');
            }
            $signature = $this->rawEcSignatureToDer($signature, $coordLen);
        }

        $pem = $this->jwkToPem($jwk);
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new RuntimeException('The identity provider\'s signing key could not be read.');
        }

        if (openssl_verify($h64 . '.' . $p64, $signature, $key, $hash) !== 1) {
            throw new RuntimeException('ID token signature is invalid.');
        }

        $claims = json_decode($this->b64($p64), true);
        if (! is_array($claims)) {
            throw new RuntimeException('ID token payload is not valid JSON.');
        }

        return $claims;
    }

    /** @param array<string,mixed> $jwks */
    private function findKey(array $jwks, string $kty, ?string $kid, string $alg): ?array
    {
        $candidates = array_values(array_filter($jwks['keys'] ?? [], function ($k) use ($kty, $alg) {
            return is_array($k)
                && ($k['kty'] ?? null) === $kty
                && (! isset($k['use']) || $k['use'] === 'sig')
                && (! isset($k['alg']) || $k['alg'] === $alg);
        }));

        if ($kid !== null) {
            foreach ($candidates as $k) {
                if (($k['kid'] ?? null) === $kid) {
                    return $k;
                }
            }

            return null;
        }

        // No kid in the header: only unambiguous when the set has exactly one key.
        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /** @param array<string,mixed> $jwk */
    public function jwkToPem(array $jwk): string
    {
        if (! empty($jwk['x5c'][0])) {
            return "-----BEGIN CERTIFICATE-----\n" . chunk_split((string) $jwk['x5c'][0], 64, "\n") . "-----END CERTIFICATE-----\n";
        }

        if (($jwk['kty'] ?? null) === 'RSA') {
            $n = $this->b64((string) ($jwk['n'] ?? ''));
            $e = $this->b64((string) ($jwk['e'] ?? ''));
            if ($n === '' || $e === '') {
                throw new RuntimeException('RSA key is missing n/e.');
            }
            $rsaKey = $this->der(0x30, $this->derInt($n) . $this->derInt($e));
            $algId  = $this->der(0x30, $this->der(0x06, "\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01") . "\x05\x00");
            $spki   = $this->der(0x30, $algId . $this->der(0x03, "\x00" . $rsaKey));

            return $this->pem($spki);
        }

        if (($jwk['kty'] ?? null) === 'EC') {
            $curves = [
                'P-256' => "\x2a\x86\x48\xce\x3d\x03\x01\x07",
                'P-384' => "\x2b\x81\x04\x00\x22",
                'P-521' => "\x2b\x81\x04\x00\x23",
            ];
            $crv = (string) ($jwk['crv'] ?? '');
            if (! isset($curves[$crv])) {
                throw new RuntimeException("Unsupported EC curve '{$crv}'.");
            }
            $point = "\x04" . $this->b64((string) ($jwk['x'] ?? '')) . $this->b64((string) ($jwk['y'] ?? ''));
            $algId = $this->der(0x30, $this->der(0x06, "\x2a\x86\x48\xce\x3d\x02\x01") . $this->der(0x06, $curves[$crv]));
            $spki  = $this->der(0x30, $algId . $this->der(0x03, "\x00" . $point));

            return $this->pem($spki);
        }

        throw new RuntimeException('Unsupported key type in the identity provider\'s key set.');
    }

    private function rawEcSignatureToDer(string $raw, int $len): string
    {
        return $this->der(0x30, $this->derInt(substr($raw, 0, $len)) . $this->derInt(substr($raw, $len)));
    }

    private function derInt(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || (ord($bytes[0]) & 0x80)) {
            $bytes = "\x00" . $bytes;
        }

        return $this->der(0x02, $bytes);
    }

    private function der(int $tag, string $value): string
    {
        $len = strlen($value);
        if ($len < 0x80) {
            return chr($tag) . chr($len) . $value;
        }
        $lenBytes = ltrim(pack('N', $len), "\x00");

        return chr($tag) . chr(0x80 | strlen($lenBytes)) . $lenBytes . $value;
    }

    private function pem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private function b64(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        if ($decoded === false) {
            throw new RuntimeException('Token segment is not valid base64url.');
        }

        return $decoded;
    }
}
