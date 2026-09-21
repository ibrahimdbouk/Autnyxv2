<?php

namespace App\Services\Integrations;

use App\Models\ApiConnection;
use App\Models\ApiFeed;
use App\Services\Integrations\Contracts\Connector;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The workhorse REST/OData connector. Handles auth and pagination generically and
 * maps each record to canonical-ish headers, so every provider (SAP S/4HANA,
 * Dynamics, Oracle, Shopify, Blue Yonder, RELEX, Slimstock, or any REST source)
 * is this class + a Profiles preset. A feed overrides any preset default.
 *
 * See claude/api-integration-library.md.
 */
class GenericRestConnector implements Connector
{
    private const MAX_PAGES = 2000; // safety bound against a misbehaving endpoint

    /** @param array<string,mixed> $profile provider defaults from Profiles::for() */
    public function __construct(private array $profile = [])
    {
    }

    public function fetch(ApiConnection $connection, ApiFeed $feed): iterable
    {
        $strategy    = $feed->page_strategy ?: ($this->profile['page_strategy'] ?? 'none');
        $recordsPath = $feed->records_path ?? ($this->profile['records_path'] ?? null);
        $fieldMap    = is_array($feed->field_map) ? $feed->field_map : [];
        $size        = max(1, (int) ($feed->page_size ?: 100));
        $params      = is_array($feed->params) ? $feed->params : [];

        $sizeParam   = $this->profile['size_param']   ?? 'per_page';
        $pageParam   = $this->profile['page_param']   ?? 'page';
        $offsetParam = $this->profile['offset_param'] ?? 'offset';

        $requestUrl = $this->joinUrl($connection->base_url, $feed->endpoint);
        $page       = 1;
        $offset     = 0;
        $absolute   = null; // set by next_link / link_header to a full URL

        for ($i = 0; $i < self::MAX_PAGES; $i++) {
            $query = $params;
            switch ($strategy) {
                case 'page':
                    $query[$pageParam] = $page;
                    $query[$sizeParam] = $size;
                    break;
                case 'offset':
                    $query[$offsetParam] = $offset;
                    $query[$sizeParam]   = $size;
                    break;
                case 'odata_skiptop':
                    $query['$skip'] = $offset;
                    $query['$top']  = $size;
                    break;
                case 'link_header':
                    $query[$sizeParam] = $size;
                    break;
            }

            $url  = $absolute ?: $requestUrl;
            // A continuation URL already carries its own query string.
            $resp = $this->client($connection)->get($url, $absolute ? [] : $this->prepareQuery($connection, $feed, $query));

            if (! $resp->successful()) {
                throw new RuntimeException('Fetch failed: ' . $this->extractError($resp));
            }

            $json    = $resp->json();
            $records = $recordsPath ? (data_get($json, $recordsPath) ?: []) : (is_array($json) ? $json : []);
            if (! is_array($records)) {
                $records = [];
            }

            foreach ($records as $rec) {
                yield $this->mapRecord((array) $rec, $fieldMap);
            }

            $count = count($records);

            // Decide continuation.
            if ($strategy === 'none') {
                return;
            }
            if ($strategy === 'next_link') {
                // '@odata.nextLink' is a literal key with a dot in it — read it
                // directly (data_get would treat the dot as nesting).
                $absolute = is_array($json) ? ($json['@odata.nextLink'] ?? null) : null;
                if (! is_string($absolute) || $absolute === '') {
                    return;
                }
                continue;
            }
            if ($strategy === 'link_header') {
                $absolute = $this->nextFromLinkHeader($resp->header('Link'));
                if ($absolute === null) {
                    return;
                }
                continue;
            }
            // OData v2/v4 server-driven paging: follow __next / @odata.nextLink if present.
            if ($strategy === 'odata_skiptop') {
                $next = data_get($json, 'd.__next')
                    ?? data_get($json, '__next')
                    ?? (is_array($json) ? ($json['@odata.nextLink'] ?? null) : null);
                if (is_string($next) && $next !== '') {
                    $absolute = $next;
                    continue;
                }
            }
            // page / offset / odata_skiptop — stop on a short/empty page.
            if ($count < $size) {
                return;
            }
            $page++;
            $offset += $size;
        }
    }

    public function test(ApiConnection $connection): array
    {
        try {
            $resp = $this->client($connection)->get($connection->base_url);
            $ok   = $resp->status() < 400;

            return [
                'ok'      => $ok,
                'message' => $ok ? "Reached the API (HTTP {$resp->status()})." : "API returned HTTP {$resp->status()}.",
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** Build an authenticated request per the connection's auth type. */
    private function client(ApiConnection $connection): PendingRequest
    {
        $req  = Http::timeout(30)->acceptJson();
        $type = $connection->auth_type ?: ($this->profile['auth_type'] ?? ApiConnection::AUTH_NONE);

        return match ($type) {
            ApiConnection::AUTH_BEARER    => $req->withToken((string) $connection->authValue('token')),
            ApiConnection::AUTH_BASIC     => $req->withBasicAuth((string) $connection->authValue('username'), (string) $connection->authValue('password')),
            ApiConnection::AUTH_API_KEY   => $req->withHeaders([(string) $connection->authValue('header_name', 'Authorization') => (string) $connection->authValue('header_value')]),
            ApiConnection::AUTH_OAUTH2_CC => $req->withToken($this->oauthToken($connection)),
            default                       => $req,
        };
    }

    /** Client-credentials token, cached per connection until shortly before expiry. */
    private function oauthToken(ApiConnection $connection): string
    {
        $key = 'api_oauth_token_' . $connection->id;
        if ($cached = Cache::get($key)) {
            return $cached;
        }

        $payload = array_filter([
            'grant_type'    => 'client_credentials',
            'client_id'     => $connection->authValue('client_id'),
            'client_secret' => $connection->authValue('client_secret'),
            'scope'         => $connection->authValue('scope') ?: $this->oauthScope($connection),
        ], fn ($v) => $v !== null && $v !== '');

        $resp = Http::asForm()->timeout(20)->post((string) $connection->authValue('token_url'), $payload);
        if (! $resp->successful() || ! $resp->json('access_token')) {
            throw new RuntimeException("OAuth token request failed: HTTP {$resp->status()} {$resp->body()}");
        }

        $token   = (string) $resp->json('access_token');
        $expires = (int) ($resp->json('expires_in') ?: 3600);
        Cache::put($key, $token, max(60, $expires - 60));

        return $token;
    }

    /**
     * Map one API record to canonical headers. Empty map → pass through the
     * record's top-level scalars unchanged.
     *
     * @param  array<string,mixed>  $record
     * @param  array<string,string> $fieldMap  header => dot-path into the record
     * @return array<string,mixed>
     */
    protected function mapRecord(array $record, array $fieldMap): array
    {
        if ($fieldMap === []) {
            return array_map(fn ($v) => $this->coerce($v), $record);
        }

        $row = [];
        foreach ($fieldMap as $header => $path) {
            $row[$header] = $this->coerce(data_get($record, $path));
        }

        return $row;
    }

    /* ---------- extension hooks (overridden by provider subclasses) ---------- */

    /**
     * Adjust the query for one page before it is sent (not called for absolute
     * continuation URLs). Base: unchanged. SAP adds $format/$select/sap-client.
     *
     * @param  array<string,mixed>  $query
     * @return array<string,mixed>
     */
    protected function prepareQuery(ApiConnection $connection, ApiFeed $feed, array $query): array
    {
        return $query;
    }

    /** Coerce a mapped value to something CSV-writable. SAP decodes /Date(ms)/. */
    protected function coerce($value)
    {
        return is_scalar($value) || $value === null ? $value : json_encode($value);
    }

    /** Human error message from a failed response. SAP parses the OData envelope. */
    protected function extractError($response): string
    {
        return 'HTTP ' . $response->status() . ' ' . $response->body();
    }

    /**
     * Default OAuth2 scope when the connection doesn't set one. Base: none.
     * Azure-AD sources (Dynamics) derive `<resource>/.default` from the base URL.
     */
    protected function oauthScope(ApiConnection $connection): ?string
    {
        return null;
    }

    private function joinUrl(string $base, string $endpoint): string
    {
        return rtrim($base, '/') . '/' . ltrim($endpoint, '/');
    }

    /** Extract the rel="next" URL from an RFC 5988 Link header (Shopify-style). */
    private function nextFromLinkHeader(?string $header): ?string
    {
        if (! $header) {
            return null;
        }
        foreach (explode(',', $header) as $part) {
            if (preg_match('/<([^>]+)>\s*;\s*rel="?next"?/i', $part, $m)) {
                return $m[1];
            }
        }

        return null;
    }
}
