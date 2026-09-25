<?php

namespace App\Jobs\Ops;

use App\Services\Ops\TenantOffboardingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * WP6.7 — erases a tenant in bounded slices: each run deletes for up to
 * ~20 minutes (under the worker timeout) and queues the next slice until the
 * tenant is gone. A crash or deploy just means the next slice resumes.
 */
class EraseTenantJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(public int $tenantId) {}

    public function uniqueId(): string
    {
        return 'erase-tenant-' . $this->tenantId;
    }

    public function handle(TenantOffboardingService $offboarding): void
    {
        if (! $offboarding->eraseChunked($this->tenantId, 1200)) {
            self::dispatch($this->tenantId)->delay(now()->addSeconds(5));
        }
    }
}
