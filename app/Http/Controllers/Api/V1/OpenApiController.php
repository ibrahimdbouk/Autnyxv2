<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;

/**
 * Serves the OpenAPI 3.0 description of the public API (unauthenticated), so
 * clients and tools can discover the endpoints, auth scheme and scopes.
 */
class OpenApiController
{
    public function spec(Request $request)
    {
        $listParams = [
            ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 25, 'maximum' => 100]],
            ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 1]],
            ['name' => 'since', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date-time']],
        ];

        $secured = fn (string $scope) => [['ApiKeyAuth' => [$scope]]];

        return response()->json([
            'openapi' => '3.0.3',
            'info'    => [
                'title'       => 'Autnyx Public API',
                'version'     => '1.0.0',
                'description' => 'Read investigations, anomalies, recoveries and data-health, and push data in. Authenticate with an API key as a Bearer token (or X-Api-Key header). Each key is scoped and tenant-bound.',
            ],
            'servers'    => [['url' => rtrim(config('app.url'), '/') . '/api/v1']],
            'components' => [
                'securitySchemes' => [
                    'ApiKeyAuth' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'An Autnyx API key (atx_...).'],
                ],
            ],
            'paths' => [
                '/investigations' => ['get' => [
                    'summary' => 'List investigations', 'security' => $secured('read:investigations'),
                    'parameters' => array_merge($listParams, [
                        ['name' => 'status', 'in' => 'query', 'schema' => ['type' => 'string']],
                        ['name' => 'priority', 'in' => 'query', 'schema' => ['type' => 'string']],
                    ]),
                    'responses' => ['200' => ['description' => 'OK']],
                ]],
                '/investigations/{id}' => ['get' => [
                    'summary' => 'Get an investigation', 'security' => $secured('read:investigations'),
                    'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
                    'responses' => ['200' => ['description' => 'OK'], '404' => ['description' => 'Not found']],
                ]],
                '/anomalies' => ['get' => [
                    'summary' => 'List anomalies', 'security' => $secured('read:anomalies'),
                    'parameters' => array_merge($listParams, [
                        ['name' => 'severity', 'in' => 'query', 'schema' => ['type' => 'string']],
                        ['name' => 'lifecycle_state', 'in' => 'query', 'schema' => ['type' => 'string']],
                        ['name' => 'rule_type', 'in' => 'query', 'schema' => ['type' => 'string']],
                    ]),
                    'responses' => ['200' => ['description' => 'OK']],
                ]],
                '/anomalies/{id}' => ['get' => [
                    'summary' => 'Get an anomaly', 'security' => $secured('read:anomalies'),
                    'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
                    'responses' => ['200' => ['description' => 'OK'], '404' => ['description' => 'Not found']],
                ]],
                '/recoveries' => ['get' => [
                    'summary' => 'List recovery outcomes', 'security' => $secured('read:recoveries'),
                    'parameters' => $listParams, 'responses' => ['200' => ['description' => 'OK']],
                ]],
                '/data-health' => ['get' => [
                    'summary' => 'Latest data-health snapshot per dataset', 'security' => $secured('read:data_health'),
                    'responses' => ['200' => ['description' => 'OK']],
                ]],
                '/exports' => ['get' => [
                    'summary' => 'BI export datasets (Power BI / Excel)', 'security' => $secured('read:exports'),
                    'responses' => ['200' => ['description' => 'The datasets, their columns and URLs']],
                ]],
                '/exports/{dataset}' => ['get' => [
                    'summary' => 'One BI dataset as a flat table — JSON pages by id, or the whole table as CSV',
                    'security' => $secured('read:exports'),
                    'parameters' => [
                        ['name' => 'dataset', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string',
                            'enum' => array_keys(\App\Services\Api\BiExport::catalogue())]],
                        ['name' => 'format', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['json', 'csv'], 'default' => 'json']],
                        ['name' => 'since', 'in' => 'query', 'description' => 'Only rows changed since (updated_at).', 'schema' => ['type' => 'string', 'format' => 'date-time']],
                        ['name' => 'after', 'in' => 'query', 'description' => 'JSON paging: rows with id above this.', 'schema' => ['type' => 'integer']],
                        ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 5000, 'maximum' => 10000]],
                    ],
                    'responses' => ['200' => ['description' => 'OK'], '404' => ['description' => 'Unknown dataset']],
                ]],
                '/ingest' => ['post' => [
                    'summary' => 'Push data into Autnyx (queued; poll /imports/{id})', 'security' => $secured('write:ingest'),
                    'parameters' => [['name' => 'Idempotency-Key', 'in' => 'header', 'required' => false,
                        'description' => 'Retries with the same key within 24h return the original import instead of creating a duplicate.',
                        'schema' => ['type' => 'string']]],
                    'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
                        'type' => 'object', 'required' => ['data_type', 'rows'],
                        'properties' => [
                            'data_type' => ['type' => 'string', 'example' => 'sales_transactions'],
                            'rows'      => ['type' => 'array', 'items' => ['type' => 'object']],
                        ],
                    ]]]],
                    'responses' => [
                        '202' => ['description' => 'Accepted — processing is queued'],
                        '200' => ['description' => 'Idempotent replay of an earlier request'],
                        '409' => ['description' => 'The same Idempotency-Key is being processed right now'],
                        '422' => ['description' => 'Validation error (the users data type is not accepted over the API)'],
                    ],
                ]],
                '/imports/{id}' => ['get' => [
                    'summary' => 'Status of a queued ingest', 'security' => $secured('write:ingest'),
                    'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
                    'responses' => ['200' => ['description' => 'Import status'], '404' => ['description' => 'Not found']],
                ]],
            ],
        ], 200, [], JSON_UNESCAPED_SLASHES);
    }
}
