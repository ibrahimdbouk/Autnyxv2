<?php

namespace App\Services\Integrations;

use App\Models\ApiConnection;

/**
 * Oracle NetSuite — SuiteTalk REST Record Service, secured with **OAuth 1.0a
 * token-based authentication (TBA)**, HMAC-SHA256. NetSuite doesn't accept a
 * bearer token or basic auth here, so the connection's `auth_type` is `none` and
 * this connector signs every request itself.
 *
 * `auth_config` holds: `account` (realm, e.g. 1234567 or 1234567_SB1),
 * `consumer_key`, `consumer_secret`, `token_id`, `token_secret`.
 * Base URL: `https://<account>.suitetalk.api.netsuite.com/services/rest`.
 * Records come back under `items` with `hasMore` + a rel="next" link (profile
 * uses the `hasmore` strategy).
 *
 * (SuiteQL — a POST query endpoint — is a later addition; this covers the GET
 * record service.) See claude/api-integration-library.md.
 */
class NetSuiteConnector extends GenericRestConnector
{
    protected function signedHeaders(ApiConnection $connection, string $method, string $url, array $query): array
    {
        $consumerKey    = (string) $connection->authValue('consumer_key');
        $consumerSecret = (string) $connection->authValue('consumer_secret');
        $tokenId        = (string) $connection->authValue('token_id');
        $tokenSecret    = (string) $connection->authValue('token_secret');
        $realm          = strtoupper((string) $connection->authValue('account'));

        if ($consumerKey === '' || $tokenId === '') {
            return []; // not configured — let the request fail clearly upstream
        }

        // Split the base URL from any query it already carries, and merge that
        // with the query we're about to send — OAuth1 signs ALL request params.
        $parts    = explode('?', $url, 2);
        $baseUrl  = $parts[0];
        $urlQuery = [];
        if (isset($parts[1])) {
            parse_str($parts[1], $urlQuery);
        }
        $allParams = array_merge($urlQuery, $query);

        $oauth = [
            'oauth_consumer_key'     => $consumerKey,
            'oauth_nonce'            => bin2hex(random_bytes(16)),
            'oauth_signature_method' => 'HMAC-SHA256',
            'oauth_timestamp'        => (string) time(),
            'oauth_token'            => $tokenId,
            'oauth_version'          => '1.0',
        ];

        // Signature base string: METHOD&url&sorted-encoded-params.
        $encoded = [];
        foreach (array_merge($allParams, $oauth) as $k => $v) {
            $encoded[rawurlencode((string) $k)] = rawurlencode((string) $v);
        }
        ksort($encoded);
        $paramString = implode('&', array_map(
            fn ($k, $v) => $k . '=' . $v,
            array_keys($encoded),
            array_values($encoded),
        ));

        $baseString = strtoupper($method) . '&' . rawurlencode($baseUrl) . '&' . rawurlencode($paramString);
        $signingKey = rawurlencode($consumerSecret) . '&' . rawurlencode($tokenSecret);
        $oauth['oauth_signature'] = base64_encode(hash_hmac('sha256', $baseString, $signingKey, true));

        // Authorization header (realm first, then the oauth_* params).
        $header = 'OAuth realm="' . $realm . '"';
        foreach ($oauth as $k => $v) {
            $header .= ', ' . $k . '="' . rawurlencode((string) $v) . '"';
        }

        return ['Authorization' => $header];
    }
}
