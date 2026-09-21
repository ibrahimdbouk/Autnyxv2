<?php

namespace App\Services\Integrations;

use App\Models\ApiConnection;
use App\Models\ApiFeed;
use Carbon\Carbon;

/**
 * SAP S/4HANA OData hardening on top of the generic connector.
 *
 * What SAP needs that a plain REST source doesn't:
 *  - `$format=json` — SAP OData defaults to XML/Atom; without it the body won't parse.
 *  - `$select` — SAP entity sets are very wide; request only the mapped fields.
 *  - `sap-client` — the client/mandant number, when the service requires it.
 *  - OData v2 dates arrive as `/Date(1609459200000)/` → normalised to ISO 8601.
 *  - OData error envelope `{"error":{"message":{"value":"…"}}}` → readable message.
 *  - Server-driven paging via `d.__next` is followed (handled in the base class).
 *
 * Collections live under `d.results` (v2) or `value` (v4) — set per feed via
 * records_path (the profile defaults to v2's `d.results`). Auth: the SAP API
 * Business Hub sandbox uses an `APIKey` header (api_key_header); a real S/4HANA
 * Cloud tenant uses OAuth2 or basic — set on the connection.
 *
 * See claude/api-integration-library.md.
 */
class SapS4HanaConnector extends GenericRestConnector
{
    protected function prepareQuery(ApiConnection $connection, ApiFeed $feed, array $query): array
    {
        // Force JSON — SAP OData otherwise returns XML/Atom.
        $query['$format'] = $query['$format'] ?? 'json';

        // Request only the fields we map (SAP entity sets are very wide).
        if (! isset($query['$select'])) {
            $select = $this->selectFields($feed);
            if ($select !== '') {
                $query['$select'] = $select;
            }
        }

        // Client / mandant number, when configured (e.g. 100).
        $client = $connection->authValue('sap_client');
        if ($client !== null && $client !== '') {
            $query['sap-client'] = $client;
        }

        return $query;
    }

    /** OData v2 `/Date(ms[±zzzz])/` → ISO 8601; everything else falls through. */
    protected function coerce($value)
    {
        if (is_string($value) && preg_match('#^/Date\((-?\d+)([+-]\d+)?\)/$#', $value, $m)) {
            return Carbon::createFromTimestampMs((int) $m[1])->toIso8601String();
        }

        return parent::coerce($value);
    }

    /** SAP OData error envelope → a readable message. */
    protected function extractError($response): string
    {
        $json = $response->json();
        $message = data_get($json, 'error.message.value') ?? data_get($json, 'error.message');

        if (is_string($message) && $message !== '') {
            return 'HTTP ' . $response->status() . ' — ' . $message;
        }

        return parent::extractError($response);
    }

    /** Distinct top-level field names referenced by the feed's field_map. */
    private function selectFields(ApiFeed $feed): string
    {
        $map    = is_array($feed->field_map) ? $feed->field_map : [];
        $fields = [];

        foreach ($map as $path) {
            $top = strtok((string) $path, '.');
            // Only simple properties are safe in $select (skip navigation/expand paths).
            if ($top !== false && $top !== '' && $top === (string) $path) {
                $fields[$top] = true;
            }
        }

        return implode(',', array_keys($fields));
    }
}
