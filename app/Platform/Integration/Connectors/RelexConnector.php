<?php

namespace App\Platform\Integration\Connectors;

/**
 * P2.1 — RELEX outbound connector. RELEX is the flagship F&R target for grocery;
 * it accepts the action-intent envelope (exception / reorder-point / forecast
 * override) over its REST integration API. Auth per the target's config (bearer /
 * OAuth2). Shares the authenticated POST; vendor-specific payload shaping can be
 * added here without touching the dispatcher.
 */
class RelexConnector extends RestApiConnector
{
}
