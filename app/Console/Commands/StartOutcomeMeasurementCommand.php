<?php

namespace App\Console\Commands;

use App\Models\Action;
use App\Models\InvestigationOutcome;
use App\Services\OutcomeService;
use Illuminate\Console\Command;

/**
 * W11 — outcomes recorded before the proof-of-value loop existed were never
 * measured: measurement starts from a completed action, and none was logged.
 * This gives each resolved outcome without one a "fix recorded with the
 * outcome" action, dated when the investigation was resolved, so the nightly
 * measurement picks it up. Dry run by default.
 */
class StartOutcomeMeasurementCommand extends Command
{
    protected $signature = 'outcomes:start-measurement {--tenant= : Only this tenant} {--apply : Create the actions}';

    protected $description = 'Start measuring resolved outcomes that have no completed action (dry run unless --apply)';

    public function handle(OutcomeService $outcomes): int
    {
        $rows = InvestigationOutcome::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('tenant_id', (int) $t))
            ->where('outcome_type', InvestigationOutcome::TYPE_RESOLVED)
            ->where(fn ($q) => $q->whereNull('was_false_positive')->orWhere('was_false_positive', false))
            ->whereDoesntHave('investigation.actions', fn ($q) => $q->where('status', Action::STATUS_COMPLETED))
            ->with('investigation')
            ->get();

        $this->line($rows->count() . ' resolved outcome(s) with no completed action' . ($this->option('apply') ? '' : ' (dry run)') . ':');
        foreach ($rows as $o) {
            $inv = $o->investigation;
            if (! $inv) {
                continue;
            }
            // When it was resolved (a typed "recovery from" date is a claim, and can predate the finding).
            $at = \Illuminate\Support\Carbon::parse($inv->resolved_at ?? $o->recorded_at ?? now());
            if ($inv->opened_at && $at->lt($inv->opened_at)) {
                $at = \Illuminate\Support\Carbon::parse($inv->opened_at);
            }
            $this->line("  tenant {$o->tenant_id} · investigation {$inv->id} · measured from " . \Illuminate\Support\Carbon::parse($at)->toDateString());
            if ($this->option('apply')) {
                $outcomes->ensureMeasurableFix($inv, $o, $at);
            }
        }
        if ($this->option('apply') && $rows->isNotEmpty()) {
            $this->info('Done — tonight\'s outcomes step measures them (day 3, 7 and 14 of data after the fix).');
        }

        return self::SUCCESS;
    }
}
