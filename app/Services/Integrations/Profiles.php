<?php

namespace App\Services\Integrations;

use App\Models\ApiConnection;

/**
 * Per-provider defaults for the Generic REST connector. A vendor "connector" is
 * the Generic connector + one of these presets, rather than a bespoke class —
 * every target here (SAP S/4HANA, Dynamics, Oracle, Shopify, Blue Yonder, RELEX,
 * Slimstock) is a REST/OData API that differs only in auth, pagination and the
 * path to the records array. A feed may override any of these.
 *
 * Presets are sensible starting points; the exact API version / paths are
 * confirmed per customer against their published metadata (OData $metadata,
 * OpenAPI) — see claude/api-integration-library.md.
 *
 * @phpstan-type Profile array{auth_type?:string,page_strategy?:string,records_path?:?string,page_param?:string,size_param?:string,offset_param?:string,default_headers?:array<string,string>}
 */
final class Profiles
{
    /** @return array<string,mixed> */
    public static function for(string $provider): array
    {
        return self::ALL[$provider] ?? self::ALL['generic_rest'];
    }

    /** @return array<int,string> provider keys, for the admin dropdown */
    public static function providers(): array
    {
        return array_keys(self::ALL);
    }

    /** @return array<string,string> provider => human label */
    public static function labels(): array
    {
        return [
            'generic_rest' => 'Generic REST / JSON',
            'sap_s4hana'   => 'SAP S/4HANA (OData)',
            'dynamics365'  => 'Microsoft Dynamics 365 (OData)',
            'oracle'       => 'Oracle (REST)',
            'shopify'      => 'Shopify (Admin REST)',
            'blue_yonder'  => 'Blue Yonder',
            'relex'        => 'RELEX',
            'slimstock'    => 'Slimstock',
        ];
    }

    private const ALL = [
        'generic_rest' => [
            'auth_type'     => ApiConnection::AUTH_BEARER,
            'page_strategy' => 'none',
            'records_path'  => null,
            'page_param'    => 'page',
            'size_param'    => 'per_page',
            'offset_param'  => 'offset',
        ],
        // OData v2 returns d.results; v4 returns value. Default to v4 + $skip/$top.
        'sap_s4hana' => [
            'auth_type'     => ApiConnection::AUTH_BASIC,
            'page_strategy' => 'odata_skiptop',
            'records_path'  => 'value',
            'size_param'    => '$top',
            'offset_param'  => '$skip',
        ],
        'dynamics365' => [
            'auth_type'     => ApiConnection::AUTH_OAUTH2_CC,
            'page_strategy' => 'next_link', // @odata.nextLink
            'records_path'  => 'value',
        ],
        'oracle' => [
            'auth_type'     => ApiConnection::AUTH_BEARER,
            'page_strategy' => 'offset',
            'records_path'  => 'items',
            'size_param'    => 'limit',
            'offset_param'  => 'offset',
        ],
        'shopify' => [
            'auth_type'     => ApiConnection::AUTH_API_KEY, // X-Shopify-Access-Token
            'page_strategy' => 'link_header',
            'records_path'  => null, // resource-keyed, e.g. "orders" — set per feed
            'size_param'    => 'limit',
        ],
        'blue_yonder' => [
            'auth_type'     => ApiConnection::AUTH_OAUTH2_CC,
            'page_strategy' => 'offset',
            'records_path'  => null,
            'size_param'    => 'limit',
            'offset_param'  => 'offset',
        ],
        'relex' => [
            'auth_type'     => ApiConnection::AUTH_BEARER,
            'page_strategy' => 'offset',
            'records_path'  => null,
            'size_param'    => 'limit',
            'offset_param'  => 'offset',
        ],
        'slimstock' => [
            'auth_type'     => ApiConnection::AUTH_API_KEY,
            'page_strategy' => 'offset',
            'records_path'  => null,
            'size_param'    => 'limit',
            'offset_param'  => 'offset',
        ],
    ];
}
