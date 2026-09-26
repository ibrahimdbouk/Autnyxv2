<?php

namespace App\Jobs\Webhooks;

use App\Models\WebhookDelivery;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** W13 — one webhook delivery: tried now, then after 1m, 5m, 30m, 2h and 6h. */
class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const BACKOFF = [60, 300, 1800, 7200, 21600];

    public int $tries = 6;

    public int $timeout = 30;

    public function __construct(public int $deliveryId)
    {
    }

    public function handle(WebhookDispatcher $webhooks): void
    {
        $delivery = WebhookDelivery::find($this->deliveryId);
        if (! $delivery || $delivery->status !== WebhookDelivery::STATUS_PENDING) {
            return;
        }
        if ($webhooks->attempt($delivery)) {
            return;
        }
        $n = $delivery->fresh()->attempts;
        if ($n >= $this->tries || ! $delivery->endpoint?->active) {
            $webhooks->giveUp($delivery->fresh());

            return;
        }
        $delay = self::BACKOFF[min($n - 1, count(self::BACKOFF) - 1)];
        $delivery->forceFill(['next_attempt_at' => now()->addSeconds($delay)])->save();
        $this->release($delay);
    }
}
