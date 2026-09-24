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
    /** Safety bound against a misbehaving endpoint (a property so tests can lower it). */
    protected int $maxPages = 2000;

    /** WP3.7: 429 / 5xx are retried this many times, with backoff (Retry-After honoured). */
    private const MAX_RETRIES = 4;

    /**
     * WP3.7 — what the last fetch() saw: the highest value of the feed's
     * high-water-mark field, an OData delta link to resume from, and whether
     * the page cap cut the pull short.
     *
     * @var array{max_hwm: ?string, delta_link: ?string, page_cap_hit: bool}
     */
    public array $runState = ['max_hwm' => null, 'delta_link' => null, 'page_cap_hit' => false];

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

        // WP3.7: incremental pulls — resume an OData delta link, or substitute the
        // high-water mark into any "{{since}}" parameter (e.g. a $filter or updated_at_min).
        $this->runState = ['max_hwm' => null, 'delta_link' => null, 'page_cap_hit' => false];
        if (is_string($feed->delta_link) && $feed->delta_link !== '') {
            $absolute = $feed->delta_link;
        }
        $since  = $feed->high_water_mark ?: '1970-01-01T00:00:00Z';
        $params = array_map(fn ($v) => is_string($v) ? str_replace('{{since}}', $since, $v) : $v, $params);

        for ($i = 0; $i < $this->maxPages; $i++) {
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
                case 'hasmore':
                    $query[$offsetParam] = $offset;
                    $query[$sizeParam]   = $size;
                    break;
            }

            $url = $absolute ?: $requestUrl;
            // A continuation URL already carries its own query string.
            $sendQuery = $absolute ? [] : $this->prepareQuery($connection, $feed, $query);

            $request = $this->client($connection);
            $signed  = $this->signedHeaders($connection, 'GET', $url, $sendQuery);
            if ($signed !== []) {
                $request = $request->withHeaders($signed);
            }

            // WP2.2 (audit H12/M4): SSRF guard; server-supplied continuation
            // links must stay on the configured API host.
            $request = app(\App\Support\Http\EgressGuard::class)->apply($request, $url, $absolute ? $requestUrl : null);

            $resp = $this->getWithRetry($request, $url, $sendQuery);

            if (! $resp->successful()) {
                throw new RuntimeException('Fetch failed: ' . $this->extractError($resp));
            }

            $json    = $resp->json();
            $records = $recordsPath ? (data_get($json, $recordsPath) ?: []) : (is_array($json) ? $json : []);
            if (! is_array($records)) {
                $records = [];
            }

            $hwmField = $feed->hwm_field ?: null;
            foreach ($records as $rec) {
                $rec = (array) $rec;
                if ($hwmField !== null && is_scalar($v = data_get($rec, $hwmField)) && (string) $v > (string) $this->runState['max_hwm']) {
                    $this->runState['max_hwm'] = (string) $v;
                }
                // WP3.7: one row per nested line item (e.g. order → lines), header fields repeated.
                if ($feed->split_path) {
                    $items = data_get($rec, $feed->split_path);
                    foreach (is_array($items) ? $items : [] as $item) {
                        $merged = $this->withItem($rec, (array) $item, (string) $feed->split_path);
                        if ($fieldMap === []) {
                            unset($merged['item']);
                        }
                        yield $this->mapRecord($merged, $fieldMap);
                    }
                    continue;
                }
                yield $this->mapRecord($rec, $fieldMap);
            }

            $count = count($records);
            if (is_array($json) && is_string($json['@odata.deltaLink'] ?? null)) {
                $this->runState['delta_link'] = $json['@odata.deltaLink'];
            }

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
            // Oracle Fusion / EBS-ORDS / NetSuite: a rel="next" link if present,
            // else the `hasMore` flag + offset (more reliable than a short page).
            if ($strategy === 'hasmore') {
                $next = $this->linksNext($json);
                if ($next !== null) {
                    $absolute = $next;
                    continue;
                }
                if (data_get($json, 'hasMore') === true && $count > 0) {
                    $offset += $size;
                    continue;
                }
                return;
            }
            // WP3.7: page / offset stop on an EMPTY page, not a short one (servers
            // cap page sizes below what was asked). OData honours $top — a server
            // that pages on its own sends __next / nextLink (handled above).
            if ($count === 0 || ($strategy === 'odata_skiptop' && $count < $size)) {
                return;
            }
            $page++;
            $offset += $count;
        }

        $this->runState['page_cap_hit'] = true;
    }

    /**
     * WP3.7: a record with one nested item: header scalars, then the item's
     * fields (item wins), and the item itself under "item" for field maps.
     */
    private function withItem(array $record, array $item, string $splitPath): array
    {
        $header = array_filter($record, fn ($v) => ! is_array($v));
        data_forget($header, $splitPath);

        return array_merge($header, $item, ['item' => $item]);
    }

    /** WP3.7: GET, retrying 429 / 5xx with backoff (Retry-After honoured, capped). */
    private function getWithRetry(PendingRequest $request, string $url, array $query)
    {
        for ($attempt = 0; ; $attempt++) {
            $resp = $request->get($url, $query);
            $status = $resp->status();
            if (($status !== 429 && $status < 500) || $attempt >= self::MAX_RETRIES) {
                return $resp;
            }
            $after = (int) $resp->header('Retry-After');
            \Illuminate\Support\Sleep::sleep(min(60, $after > 0 ? $after : 2 ** $attempt));
        }
    }

    public function test(ApiConnection $connection): array
    {
        try {
            $resp = app(\App\Support\Http\EgressGuard::class)
                ->apply($this->client($connection), (string) $connection->base_url)
                ->get($connection->base_url);
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

        $tokenUrl = (string) $connection->authValue('token_url');
        $resp = app(\App\Support\Http\EgressGuard::class)
            ->apply(Http::asForm()->timeout(20), $tokenUrl)
            ->post($tokenUrl, $payload);
        if (! $resp->successful() || ! $resp->json('access_token')) {
            // WP2.2: never echo a remote body in full (it lands in last_error).
            throw new RuntimeException("OAuth token request failed: HTTP {$resp->status()} " . \Illuminate\Support\Str::limit($resp->body(), 300));
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
        // WP2.2: bounded — the remote body is stored in last_error and shown in the UI.
        return 'HTTP ' . $response->status() . ' ' . \Illuminate\Support\Str::limit((string) $response->body(), 300);
    }

    /**
     * Default OAuth2 scope when the connection doesn't set one. Base: none.
     * Azure-AD sources (Dynamics) derive `<resource>/.default` from the base URL.
     */
    protected function oauthScope(ApiConnection $connection): ?string
    {
        return null;
    }

    /**
     * Extra per-request headers computed from the final URL + query (needed for
     * signature auth). Base: none. NetSuite returns the OAuth 1.0a header.
     *
     * @param  array<string,mixed>  $query
     * @return array<string,string>
     */
    protected function signedHeaders(ApiConnection $connection, string $method, string $url, array $query): array
    {
        return [];
    }

    /** A rel="next" href from a JSON `links` array (Oracle/NetSuite style). */
    private function linksNext($json): ?string
    {
        $links = data_get($json, 'links');
        if (! is_array($links)) {
            return null;
        }
        foreach ($links as $link) {
            if (data_get($link, 'rel') === 'next') {
                $href = data_get($link, 'href');
                if (is_string($href) && $href !== '') {
                    return $href;
                }
            }
        }

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
