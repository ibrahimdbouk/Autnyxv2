<?php

namespace App\Jobs\Nightly;

use App\Models\TenantNightlyRun;
use App\Services\Pipeline\NightlyChain;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** WP5.2 — close the night (done, or failed if any step failed). */
class FinishNightlyRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $runId)
    {
    }

    public function handle(NightlyChain $chain): void
    {
        $run = TenantNightlyRun::find($this->runId);
        if ($run) {
            $chain->finish($run);
        }
    }
}
