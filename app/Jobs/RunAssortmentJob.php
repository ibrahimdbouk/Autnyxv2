<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\Assortment\AssortmentEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Assortment — one tenant's run on demand ("Run now"), off the web request. */
class RunAssortmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public int $tenantId)
    {
    }

    public function handle(AssortmentEngine $engine): void
    {
        $tenant = Tenant::find($this->tenantId);
        if ($tenant) {
            $engine->run($tenant, true);
        }
    }
}
