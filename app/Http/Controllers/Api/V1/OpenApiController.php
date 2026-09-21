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
                '/ingest' => ['post' => [
                    'summary' => 'Push data into Autnyx', 'security' => $secured('write:ingest'),
                    'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
                        'type' => 'object', 'required' => ['data_type', 'rows'],
                        'properties' => [
                            'data_type' => ['type' => 'string', 'example' => 'sales_transactions'],
                            'rows'      => ['type' => 'array', 'items' => ['type' => 'object']],
                        ],
                    ]]]],
                    'responses' => ['201' => ['description' => 'Accepted'], '422' => ['description' => 'Validation error']],
                ]],
            ],
        ], 200, [], JSON_UNESCAPED_SLASHES);
    }
}
