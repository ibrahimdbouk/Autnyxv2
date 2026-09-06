<?php

namespace App\Platform\Integration\Connectors;

use App\Models\OutboundTarget;
use App\Platform\Integration\ActionIntent;
use App\Platform\Integration\Contracts\OutboundConnector;
use App\Platform\Integration\DispatchResult;
use Illuminate\Support\Facades\Log;

/**
 * P2.1 — the safe default connector: records the intent to the log and returns
 * "sent" without any external call. Used when a tenant has no target configured,
 * and as the stand-in for flagship connectors (s4/d365/oracle/relex) until each
 * is built — so the dispatch path is exercised end-to-end with zero external risk.
 */
class LogConnector implements OutboundConnector
{
    public function dispatch(ActionIntent $intent, OutboundTarget $target): DispatchResult
    {
        Log::info('[outbound] action-intent (log connector)', [
            'kind'    => $target->kind,
            'intent'  => $intent->toArray(),
        ]);

        return new DispatchResult(DispatchResult::STATUS_SENT, null, 'logged (no external delivery)');
    }
}
