<?php

namespace App\Jobs\AI;

use App\Services\Agents\ActionFollowUpAgent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** WP5.4 — on-demand follow-ups run on the queue, not in the web request. */
class RunFollowUpsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200;

    public int $tries = 1;

    public int $uniqueFor = 1200;

    public function __construct(public int $tenantId)
    {
    }

    public function uniqueId(): string
    {
        return 'followups-' . $this->tenantId;
    }

    public function handle(ActionFollowUpAgent $agent): void
    {
        $agent->followForTenant($this->tenantId);
    }
}
