<?php

namespace App\Jobs\AI;

use App\Models\Investigation;
use App\Services\InvestigationNarratorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** WP5.4 — on-demand narration runs on the queue, not in the web request. */
class NarrateInvestigationJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function __construct(public int $investigationId, public bool $force = true)
    {
    }

    public function uniqueId(): string
    {
        return 'narrate-' . $this->investigationId;
    }

    public function handle(InvestigationNarratorService $narrator): void
    {
        if ($inv = Investigation::find($this->investigationId)) {
            $narrator->narrate($inv, $this->force);
        }
    }
}
