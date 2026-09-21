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
        $profile = Profiles::for($connection->provider);

        return match ($connection->provider) {
            'sap_s4hana'   => new SapS4HanaConnector($profile),
            'dynamics_bc'  => new DynamicsConnector($profile),
            'dynamics_fno' => new DynamicsFnoConnector($profile),
            'netsuite'     => new NetSuiteConnector($profile),
            default        => new GenericRestConnector($profile),
        };
    }
}
