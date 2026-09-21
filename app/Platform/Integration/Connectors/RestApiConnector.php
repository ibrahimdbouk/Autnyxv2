<?php

namespace App\Platform\Integration\Connectors;

use App\Models\OutboundTarget;
use App\Platform\Integration\ActionIntent;
use App\Platform\Integration\Contracts\OutboundConnector;
use App\Platform\Integration\DispatchResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * P2.1 — base connector for a first-party F&R/ERP REST target: POST the canonical
 * action-intent envelope to the target's endpoint, authenticated per the target's
 * `config`. Vendor subclasses (RELEX / Blue Yonder / Slimstock) exist so the
 * factory can route one kind → one connector and so vendor-specific payload
 * shaping can be added later; today they share this authenticated POST.
 *
 * config.auth: none | bearer | api_key_header | oauth2_client_credentials
 * config keys: token | header_name/header_value | token_url/client_id/client_secret/scope
 * Optional config.secret adds an HMAC-SHA256 X-Autnyx-Signature.
 */
class RestApiConnector implements OutboundConnector
{
    public function dispatch(ActionIntent $intent, OutboundTarget $target): DispatchResult
    {
        $payload = $intent->toArray();

        try {
            $request = $this->authorize(Http::asJson()->acceptJson()->timeout(15), $target);

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

    private function authorize(PendingRequest $request, OutboundTarget $target): PendingRequest
    {
        $config = is_array($target->config) ? $target->config : [];

        return match ($config['auth'] ?? 'none') {
            'bearer'         => $request->withToken((string) ($config['token'] ?? '')),
            'api_key_header' => $request->withHeaders([(string) ($config['header_name'] ?? 'Authorization') => (string) ($config['header_value'] ?? '')]),
            'oauth2_client_credentials' => $request->withToken($this->oauthToken($target, $config)),
            default          => $request,
        };
    }

    /** Client-credentials token, cached per target until shortly before expiry. */
    private function oauthToken(OutboundTarget $target, array $config): string
    {
        $key = 'outbound_oauth_token_' . ($target->id ?? md5((string) ($config['token_url'] ?? '')));
        if ($cached = Cache::get($key)) {
            return $cached;
        }

        $payload = array_filter([
            'grant_type'    => 'client_credentials',
            'client_id'     => $config['client_id'] ?? null,
            'client_secret' => $config['client_secret'] ?? null,
            'scope'         => $config['scope'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $resp = Http::asForm()->timeout(15)->post((string) ($config['token_url'] ?? ''), $payload);
        if (! $resp->successful() || ! $resp->json('access_token')) {
            throw new \RuntimeException('OAuth token request failed: HTTP ' . $resp->status());
        }

        $token   = (string) $resp->json('access_token');
        $expires = (int) ($resp->json('expires_in') ?: 3600);
        Cache::put($key, $token, max(60, $expires - 60));

        return $token;
    }
}
