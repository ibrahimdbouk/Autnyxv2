<?php

namespace App\Platform\Integration\Connectors;

use App\Models\OutboundTarget;
use App\Platform\Integration\ActionIntent;
use App\Platform\Integration\Contracts\OutboundConnector;
use App\Platform\Integration\DispatchResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * P2.1 — the generic webhook connector: POST the canonical action-intent envelope
 * to the target's endpoint. This is the long-tail default — the customer's iPaaS
 * (or their own receiver) maps the envelope into whatever system they run, so we
 * never write a bespoke connector for it. Optionally HMAC-signs the body.
 */
class WebhookConnector implements OutboundConnector
{
    public function dispatch(ActionIntent $intent, OutboundTarget $target): DispatchResult
    {
        $payload = $intent->toArray();

        try {
            $request = Http::asJson()->acceptJson()->timeout(15);

            $secret = $target->config['secret'] ?? null;
            if ($secret) {
                $request = $request->withHeaders([
                    'X-Autnyx-Signature' => hash_hmac('sha256', json_encode($payload), (string) $secret),
                ]);
            }

            $response = $request->post((string) $target->endpoint, $payload);

            return new DispatchResult(
                $response->successful() ? DispatchResult::STATUS_ACKNOWLEDGED : DispatchResult::STATUS_FAILED,
                $response->status(),
                Str::limit($response->body(), 1000),
            );
        } catch (\Throwable $e) {
            return new DispatchResult(DispatchResult::STATUS_FAILED, null, Str::limit($e->getMessage(), 1000));
        }
    }
}
