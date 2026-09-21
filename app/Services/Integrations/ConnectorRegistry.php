<?php

namespace App\Services\Integrations;

use App\Models\ApiConnection;
use App\Services\Integrations\Contracts\Connector;

/**
 * Resolves the connector for a connection. Today every provider is served by the
 * GenericRestConnector configured with the provider's Profiles preset; a provider
 * that ever needs bespoke behaviour can be mapped to its own class here without
 * changing callers. See claude/api-integration-library.md.
 */
class ConnectorRegistry
{
    public function for(ApiConnection $connection): Connector
    {
        return new GenericRestConnector(Profiles::for($connection->provider));
    }
}
