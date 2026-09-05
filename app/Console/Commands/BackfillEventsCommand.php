<?php

namespace App\Console\Commands;

use App\Models\Action;
use App\Models\Investigation;
use App\Models\InvestigationOutcome;
use App\Models\PlatformEvent;
use App\Services\Platform\EventStore;
use Illuminate\Console\Command;

/**
 * P1.2 — project existing business facts (actions taken, outcomes recorded) into
 * the event backbone, so the stream reflects history as well as new events.
 * Idempotent via source_ref: safe to re-run, and it won't duplicate facts already
 * captured live.
 */
class BackfillEventsCommand extends Command
{
    protected $signature = 'events:backfill {--tenant= : Tenant id (default: all)}';

    protected $description = 'Backfill the platform event backbone from existing actions and outcomes.';

    public function handle(EventStore $store): int
    {
        $tenant  = $this->option('tenant') ? (int) $this->option('tenant') : null;
        $created = 0;
        $tenantOf = [];

        // Actions (tenant is resolved via the parent investigation).
        Action::query()
            ->when($tenant, fn ($q) => $q->whereIn(
                'investigation_id',
                Investigation::where('tenant_id', $tenant)->pluck('id')
            ))
            ->orderBy('id')
            ->chunkById(500, function ($actions) use ($store, &$created, &$tenantOf) {
                foreach ($actions as $action) {
                    $tid = $tenantOf[$action->investigation_id]
                        ??= Investigation::whereKey($action->investigation_id)->value('tenant_id');
                    if (! $tid) {
                        continue;
                    }

                    $event = $store->append([
                        'tenant_id'   => $tid,
                        'event_type'  => PlatformEvent::TYPE_ACTION,
                        'occurred_at' => $action->created_at ?? now(),
                        'source'      => 'backfill',
                        'source_ref'  => 'action:' . $action->id,
                        'payload'     => [
                            'action_id'        => $action->id,
                            'action_type'      => $action->action_type,
                            'title'            => $action->title,
                            'status'           => $action->status,
                            'priority'         => $action->priority,
                            'investigation_id' => $action->investigation_id,
                            'anomaly_id'       => $action->anomaly_id,
                        ],
                    ]);

                    if ($event->wasRecentlyCreated) {
                        $created++;
                    }
                }
            });

        // Outcomes (carry tenant_id directly).
        InvestigationOutcome::query()
            ->when($tenant, fn ($q) => $q->where('tenant_id', $tenant))
            ->orderBy('id')
            ->chunkById(500, function ($outcomes) use ($store, &$created) {
                foreach ($outcomes as $outcome) {
                    if (! $outcome->tenant_id) {
                        continue;
                    }

                    $event = $store->append([
                        'tenant_id'   => $outcome->tenant_id,
                        'event_type'  => PlatformEvent::TYPE_OUTCOME,
                        'occurred_at' => $outcome->recorded_at ?? now(),
                        'source'      => 'backfill',
                        'source_ref'  => 'outcome:' . $outcome->id,
                        'value'       => $outcome->observed_recovery,
                        'payload'     => [
                            'outcome_id'        => $outcome->id,
                            'outcome_type'      => $outcome->outcome_type,
                            'revenue_at_risk'   => $outcome->revenue_at_risk,
                            'observed_recovery' => $outcome->observed_recovery,
                            'recovery_method'   => $outcome->recovery_method,
                            'investigation_id'  => $outcome->investigation_id,
                        ],
                    ]);

                    if ($event->wasRecentlyCreated) {
                        $created++;
                    }
                }
            });

        $this->info("Backfilled {$created} new event(s) into the backbone.");

        return self::SUCCESS;
    }
}
