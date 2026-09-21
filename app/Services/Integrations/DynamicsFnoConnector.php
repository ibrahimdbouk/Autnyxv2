<?php

namespace App\Services\Integrations;

use App\Models\ApiConnection;
use App\Models\ApiFeed;

/**
 * Microsoft Dynamics 365 — Finance & Operations (Finance / Supply Chain). Same
 * OData v4 + Entra OAuth2 base as Business Central, with F&O specifics:
 *  - Entities live under `/data/{EntitySet}` (feed config).
 *  - **cross-company**: F&O scopes data to one legal entity by default; set
 *    `auth_config.cross_company = true` to pull across companies (adds
 *    `cross-company=true`). Filter one entity with a feed param `dataAreaId`.
 *
 * See claude/api-integration-library.md.
 */
class DynamicsFnoConnector extends DynamicsConnector
{
    protected function prepareQuery(ApiConnection $connection, ApiFeed $feed, array $query): array
    {
        $crossCompany = $connection->authValue('cross_company');
        if ($crossCompany === true || $crossCompany === 'true' || $crossCompany === 1 || $crossCompany === '1') {
            $query['cross-company'] = 'true';
        }

        return $query;
    }
}
