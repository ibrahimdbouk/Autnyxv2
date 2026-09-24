<?php

namespace App\Support\Http;

use Illuminate\Http\Client\PendingRequest;
use RuntimeException;

/**
 * WP2.2 (audit H12, M4) — the single gate for every server-side HTTP call to a
 * URL a tenant can configure (API connections, OAuth token URLs, OIDC, Teams
 * webhooks, outbound targets). Blocks server-side request forgery:
 *
 *   • https only (EGRESS_ALLOW_HTTP=true to relax for a dev box);
 *   • no localhost / *.internal / *.local / metadata hostnames;
 *   • every resolved address must be public — no loopback, private,
 *     link-local (cloud metadata 169.254.169.254), CGNAT or reserved ranges;
 *   • the connection is PINNED to the addresses we checked (CURLOPT_RESOLVE),
 *     so a DNS-rebinding answer cannot swap in an internal IP after the check;
 *   • redirects are never followed;
 *   • optional same-host rule for server-supplied continuation links.
 */
class EgressGuard
{
    private const BLOCKED_HOSTS = ['localhost', 'metadata.google.internal', 'metadata'];
    private const BLOCKED_SUFFIXES = ['.localhost', '.local', '.internal', '.lan', '.home.arpa', '.localdomain'];

    public function __construct(private readonly HostResolver $resolver)
    {
    }

    /**
     * Apply the guard to a request about to be sent to $url.
     */
    public function apply(PendingRequest $request, string $url, ?string $sameHostAs = null): PendingRequest
    {
        return $request->withoutRedirecting()->withOptions($this->check($url, $sameHostAs));
    }

    /**
     * Validate $url; returns Guzzle options that pin the checked addresses.
     *
     * @return array<string,mixed>
     */
    public function check(string $url, ?string $sameHostAs = null): array
    {
        $parts = parse_url(trim($url));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if ($host === '' || ! in_array($scheme, ['https', 'http'], true)) {
            throw new RuntimeException('Blocked outbound URL: it must be an absolute https:// URL.');
        }
        if ($scheme === 'http' && ! config('autnyx.egress.allow_http', false)) {
            throw new RuntimeException('Blocked outbound URL: only https:// is allowed.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Blocked outbound URL: credentials in the URL are not allowed.');
        }

        if ($sameHostAs !== null) {
            $origin = strtolower((string) parse_url($sameHostAs, PHP_URL_HOST));
            if ($origin === '' || $origin !== $host) {
                throw new RuntimeException("Blocked outbound URL: a continuation link pointed to another host ({$host}).");
            }
        }

        if (in_array($host, self::BLOCKED_HOSTS, true)) {
            throw new RuntimeException("Blocked outbound URL: {$host} is an internal host.");
        }
        foreach (self::BLOCKED_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                throw new RuntimeException("Blocked outbound URL: {$host} is an internal host.");
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $this->assertPublic($host);

            return [];
        }

        if (! config('autnyx.egress.resolve_dns', true)) {
            return [];
        }

        $ips = $this->resolver->resolve($host);
        if ($ips === []) {
            throw new RuntimeException("Blocked outbound URL: {$host} does not resolve.");
        }
        foreach ($ips as $ip) {
            $this->assertPublic($ip);
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        return ['curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:" . implode(',', $ips)]]];
    }

    /** Host-only variant for non-HTTP protocols (SFTP). */
    public function assertPublicHost(string $host): void
    {
        $this->check('https://' . trim($host, '[]'));
    }

    public function isPublicIp(string $ip): bool
    {
        // IPv4-mapped IPv6 (::ffff:10.0.0.1) → check the IPv4 part.
        if (stripos($ip, '::ffff:') === 0 && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ip = substr($ip, 7);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            foreach ([['100.64.0.0', 10], ['169.254.0.0', 16], ['0.0.0.0', 8], ['192.0.0.0', 24], ['198.18.0.0', 15]] as [$net, $bits]) {
                $mask = -1 << (32 - $bits);
                if (($long & $mask) === (ip2long($net) & $mask)) {
                    return false;
                }
            }

            return true;
        }

        // IPv6: loopback, unique-local fc00::/7, link-local fe80::/10.
        $bin = inet_pton($ip);
        if ($bin === false || $ip === '::1' || $ip === '::') {
            return false;
        }
        $first = ord($bin[0]);
        if (($first & 0xFE) === 0xFC) {
            return false;
        }
        if ($first === 0xFE && (ord($bin[1]) & 0xC0) === 0x80) {
            return false;
        }

        return true;
    }

    private function assertPublic(string $ip): void
    {
        if (! $this->isPublicIp($ip)) {
            throw new RuntimeException('Blocked outbound URL: it resolves to a private or internal address.');
        }
    }
}
