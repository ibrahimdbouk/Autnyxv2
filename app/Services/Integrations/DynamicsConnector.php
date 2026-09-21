<?php

namespace App\Services\Integrations;

use App\Models\ApiConnection;

/**
 * Microsoft Dynamics 365 — Business Central. OData v4 behind Entra (Azure AD)
 * OAuth2 client credentials. Shared base for Finance & Operations too.
 *
 * What it hardens over the generic connector:
 *  - **Azure AD scope**: if the connection sets no scope, derive `<resource>/.default`
 *    from the base URL (e.g. https://api.businesscentral.dynamics.com/.default) — the
 *    v2 client-credentials flow requires the resource's `.default` scope.
 *  - **OData v4 error envelope** `{"error":{"code","message"}}` (message is a plain
 *    string in v4, sometimes `{value}`) → a readable message.
 *  - Paging via `@odata.nextLink` and records under `value` are handled by the base
 *    (set page_strategy `next_link`, records_path `value` — the profile default).
 *
 * BC is company-scoped: the company lives in the endpoint path
 * (`/companies({id})/{entity}`), so it's plain feed config, not special-cased here.
 *
 * See claude/api-integration-library.md.
 */
class DynamicsConnector extends GenericRestConnector
{
    protected function oauthScope(ApiConnection $connection): ?string
    {
        $parts = parse_url((string) $connection->base_url);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        return $parts['scheme'] . '://' . $parts['host'] . '/.default';
    }

    protected function extractError($response): string
    {
        $json    = $response->json();
        $message = data_get($json, 'error.message.value') ?? data_get($json, 'error.message');
        $code    = data_get($json, 'error.code');

        if (is_string($message) && $message !== '') {
            return 'HTTP ' . $response->status() . ' — ' . ($code ? "[{$code}] " : '') . $message;
        }

        return parent::extractError($response);
    }
}
