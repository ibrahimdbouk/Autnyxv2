<?php

namespace App\Services\Sso;

use App\Models\SsoConnection;
use Illuminate\Support\Str;

/**
 * WP2.1 (audit M8) — a tenant must PROVE it owns an email domain before that
 * domain can route people to its identity provider or sign them in.
 *
 * Proof: a DNS TXT record on the domain containing this connection's token
 * ("autnyx-sso-verification=<token>"). A domain can be verified by exactly one
 * connection platform-wide, so one tenant can never claim another company's
 * domain and phish its staff through a look-alike IdP.
 */
class SsoDomainVerifier
{
    public const TXT_PREFIX = 'autnyx-sso-verification=';

    public function __construct(private readonly DnsTxtResolver $dns)
    {
    }

    public function ensureToken(SsoConnection $conn): string
    {
        if (empty($conn->domain_verification_token)) {
            $conn->forceFill(['domain_verification_token' => Str::random(40)])->save();
        }

        return (string) $conn->domain_verification_token;
    }

    public function expectedTxt(SsoConnection $conn): string
    {
        return self::TXT_PREFIX . $this->ensureToken($conn);
    }

    /**
     * Check every allowed domain; keep verified the ones whose TXT record carries
     * the token and that no OTHER connection has already verified.
     *
     * @return array{verified: list<string>, failed: array<string,string>}
     */
    public function verify(SsoConnection $conn): array
    {
        $expected = $this->expectedTxt($conn);
        $verified = [];
        $failed   = [];

        foreach ($conn->allowedDomains() as $domain) {
            if ($this->claimedElsewhere($conn, $domain)) {
                $failed[$domain] = 'already verified by another organisation';
                continue;
            }

            $records = array_map(fn ($r) => trim($r, "\" \t"), $this->dns->txt($domain));
            if (in_array($expected, $records, true)) {
                $verified[] = $domain;
            } else {
                $failed[$domain] = 'TXT record not found';
            }
        }

        $conn->forceFill(['verified_domains' => $verified])->save();

        return ['verified' => $verified, 'failed' => $failed];
    }

    private function claimedElsewhere(SsoConnection $conn, string $domain): bool
    {
        return SsoConnection::query()
            ->where('id', '!=', $conn->id)
            ->whereNotNull('verified_domains')
            ->get(['id', 'allowed_domains', 'verified_domains'])
            ->contains(fn (SsoConnection $c) => in_array($domain, $c->verifiedDomains(), true));
    }
}
