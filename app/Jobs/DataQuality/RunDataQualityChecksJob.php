<?php

namespace App\Jobs\DataQuality;

use App\Services\DataQuality\DataQualityChecks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * W9 (WP9.3) — the data checks after an import, off the request. One per
 * tenant at a time: a burst of imports collapses into one run.
 */
class RunDataQualityChecksJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 300;
    public int $uniqueFor = 600;

    public function __construct(public int $tenantId)
    {
    }

    public function uniqueId(): string
    {
        return 'dq-checks-' . $this->tenantId;
    }

    public function handle(DataQualityChecks $checks): void
    {
        $checks->run($this->tenantId);
    }
}
