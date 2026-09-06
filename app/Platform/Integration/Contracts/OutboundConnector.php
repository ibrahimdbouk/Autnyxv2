<?php

namespace App\Platform\Integration\Contracts;

use App\Models\OutboundTarget;
use App\Platform\Integration\ActionIntent;
use App\Platform\Integration\DispatchResult;

/**
 * P2.1 — a connector translates the canonical {@see ActionIntent} into a target
 * system's shape and delivers it. One implementation per target *kind* (webhook,
 * s4, d365, oracle, relex …) — the only place per-target code should ever live.
 */
interface OutboundConnector
{
    public function dispatch(ActionIntent $intent, OutboundTarget $target): DispatchResult;
}
