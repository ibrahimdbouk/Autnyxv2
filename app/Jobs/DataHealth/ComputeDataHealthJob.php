<?php

namespace App\Jobs\DataHealth;

use App\Models\DataHealthSnapshot;
use App\Services\DataHealth\DataHealthService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * WP6.4 (audit H32) — Data Health recomputed off the web request: the whole
 * tenant (the page's Recompute button, first visit), or one dataset right
 * after an import of it. One pending job per tenant + dataset.
 */
class ComputeDataHealthJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;
    public int $tries = 2;
    public int $uniqueFor = 900;

    public function __construct(public int $tenantId, public ?string $dataset = null) {}

    public function uniqueId(): string
    {
        return $this->tenantId . ':' . ($this->dataset ?? 'all');
    }

    public function handle(DataHealthService $service): void
    {
        if ($this->dataset === null) {
            $service->computeForTenant($this->tenantId);

            return;
        }

        DataHealthSnapshot::updateOrCreate(
            ['tenant_id' => $this->tenantId, 'dataset' => $this->dataset],
            $service->computeDataset($this->tenantId, $this->dataset) + ['computed_at' => now()],
        );
    }
}
