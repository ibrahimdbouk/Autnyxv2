<?php

namespace App\Console\Commands;

use App\Models\Action;
use App\Models\Investigation;
use App\Platform\Integration\ActionIntent;
use App\Platform\Integration\OutboundDispatcher;
use Illuminate\Console\Command;

/**
 * P2.1 — emit one remediation action to the tenant's replenishment system of
 * record. The app builds the canonical ActionIntent from its own Action here
 * (keeping the platform dispatcher free of app coupling) and hands it off.
 */
class DispatchActionCommand extends Command
{
    protected $signature = 'outbound:dispatch {action : Action id}';

    protected $description = "Emit a remediation action to the tenant's replenishment system of record.";

    public function handle(OutboundDispatcher $dispatcher): int
    {
        $action = Action::find($this->argument('action'));
        if (! $action) {
            $this->error('Action not found.');
            return self::FAILURE;
        }

        $investigation = Investigation::find($action->investigation_id);
        $tenantId = $investigation?->tenant_id;
        if (! $tenantId) {
            $this->error('Could not resolve the tenant for this action.');
            return self::FAILURE;
        }

        $intent = new ActionIntent(
            tenantId: $tenantId,
            intentType: (string) $action->action_type,
            sku: $investigation?->primary_sku,
            rationale: (string) $action->title,
            expectedValue: (float) ($investigation?->revenue_at_risk ?? 0),
            source: 'action:' . $action->id,
            metadata: [
                'action_id' => $action->id,
                'status'    => $action->status,
                'priority'  => $action->priority,
            ],
        );

        $dispatch = $dispatcher->dispatch($intent);

        $this->info("Dispatched action {$action->id} → dispatch #{$dispatch->id} (status: {$dispatch->status}).");

        return self::SUCCESS;
    }
}
