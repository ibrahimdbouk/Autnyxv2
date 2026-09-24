<?php

namespace App\Services\Sso;

/** Thin, swappable DNS TXT lookup (bound in the container so tests can fake it). */
class DnsTxtResolver
{
    /** @return list<string> */
    public function txt(string $domain): array
    {
        $records = @dns_get_record($domain, DNS_TXT);
        if (! is_array($records)) {
            return [];
        }

        $out = [];
        foreach ($records as $r) {
            if (isset($r['txt'])) {
                $out[] = (string) $r['txt'];
            } elseif (isset($r['entries']) && is_array($r['entries'])) {
                $out[] = implode('', $r['entries']);
            }
        }

        return $out;
    }
}
