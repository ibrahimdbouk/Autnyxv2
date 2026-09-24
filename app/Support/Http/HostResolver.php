<?php

namespace App\Support\Http;

/** Swappable DNS A/AAAA resolution (bound in the container so tests can fake it). */
class HostResolver
{
    /** @return list<string> IP addresses for the host (empty when unresolvable) */
    public function resolve(string $host): array
    {
        $ips = [];
        foreach ((array) @dns_get_record($host, DNS_A | DNS_AAAA) as $r) {
            if (isset($r['ip'])) {
                $ips[] = $r['ip'];
            } elseif (isset($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }
        if ($ips === []) {
            $ips = (array) (@gethostbynamel($host) ?: []);
        }

        return array_values(array_unique($ips));
    }
}
