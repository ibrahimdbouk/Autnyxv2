<?php

namespace App\Console\Commands;

use App\Jobs\Webhooks\DeliverWebhookJob;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Console\Command;

/**
 * W13 — webhooks housekeeping.
 *   webhooks:findings --tenant=  queue finding.opened for new findings (nightly, after detection)
 *   webhooks:findings --retry    re-queue deliveries whose retry is overdue (a lost queue job)
 */
class WebhooksCommand extends Command
{
    protected $signature = 'webhooks:findings {--tenant= : One tenant} {--retry : Re-queue overdue retries instead}';

    protected $description = 'Send finding.opened webhooks for new findings, or re-queue overdue webhook retries';

    public function handle(WebhookDispatcher $webhooks): int
    {
        if ($this->option('retry')) {
            $n = 0;
            WebhookDelivery::where('status', WebhookDelivery::STATUS_PENDING)
                ->where('next_attempt_at', '<', now()->subMinutes(30))
                ->when($this->option('tenant'), fn ($q, $t) => $q->where('tenant_id', (int) $t))
                ->orderBy('id')->limit(1000)->get(['id'])
                ->each(function ($d) use (&$n) {
                    DeliverWebhookJob::dispatch($d->id);
                    $n++;
                });
            $this->info("{$n} overdue delivery(ies) re-queued.");

            return self::SUCCESS;
        }

        $tenants = Tenant::query()->when($this->option('tenant'), fn ($q, $t) => $q->whereKey((int) $t))
            ->whereIn('id', \App\Models\WebhookEndpoint::where('active', true)->select('tenant_id'))->pluck('id');
        $total = 0;
        foreach ($tenants as $id) {
            $total += $webhooks->sweepFindings((int) $id);
        }
        $this->info("{$total} finding.opened delivery(ies) queued.");

        return self::SUCCESS;
    }
}
