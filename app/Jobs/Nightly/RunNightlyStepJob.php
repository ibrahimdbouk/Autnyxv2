<?php

namespace App\Jobs\Nightly;

use App\Models\TenantNightlyRun;
use App\Services\Pipeline\NightlyChain;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** WP5.2 — one step of one tenant's night (see NightlyChain). */
class RunNightlyStepJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Below the database queue's retry_after (1810s), so a long step is never run twice. */
    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public int $runId, public string $step)
    {
    }

    public function handle(NightlyChain $chain): void
    {
        $run = TenantNightlyRun::find($this->runId);
        if ($run) {
            $chain->runStep($run, $this->step);
        }
    }
}
