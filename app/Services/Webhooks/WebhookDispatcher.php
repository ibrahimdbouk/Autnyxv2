<?php

namespace App\Services\Webhooks;

use App\Filament\Resources\InvestigationResource;
use App\Jobs\Webhooks\DeliverWebhookJob;
use App\Models\Anomaly;
use App\Models\AnomalySetting;
use App\Models\AuditLog;
use App\Models\CycleCount;
use App\Models\Investigation;
use App\Models\InvestigationOutcome;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\Detection\ValueModel;
use App\Support\Http\EgressGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * W13 — outbound webhooks. Notification only: Autnyx tells another system that
 * something happened; it never carries out anything on the strength of a
 * reply (responses are logged, never read for instructions).
 *
 * Each delivery is
 *   • signed: X-Autnyx-Signature: t=<unix time>,v1=<HMAC-SHA256(secret, "<t>.<body>")>;
 *   • sent through the egress guard (https, public hosts only, no redirects);
 *   • logged (webhook_deliveries) and retried with backoff (1m, 5m, 30m, 2h, 6h);
 *   • counted against the endpoint: after DISABLE_AFTER failures in a row the
 *     endpoint is switched off and the switch-off is audited.
 */
class WebhookDispatcher
{
    public const MAX_FINDINGS_PER_RUN = 200;

    public const SEVERITY_RANK = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];

    /** Queue $event for every active endpoint of the tenant that subscribes to it. */
    public function emit(int $tenantId, string $event, array $data): int
    {
        $endpoints = WebhookEndpoint::where('tenant_id', $tenantId)->where('active', true)->get()
            ->filter(fn (WebhookEndpoint $e) => $e->wants($event));
        foreach ($endpoints as $endpoint) {
            $this->queue($endpoint, $event, $data);
        }

        return $endpoints->count();
    }

    public function queue(WebhookEndpoint $endpoint, string $event, array $data, bool $dispatch = true): WebhookDelivery
    {
        $id = (string) Str::uuid();
        $delivery = WebhookDelivery::create([
            'tenant_id'           => $endpoint->tenant_id,
            'webhook_endpoint_id' => $endpoint->id,
            'event_id'            => $id,
            'event'               => $event,
            'payload'             => ['id' => $id, 'event' => $event, 'created_at' => now()->toIso8601ZuluString(),
                'tenant_id' => (int) $endpoint->tenant_id, 'data' => $data],
            'status'              => WebhookDelivery::STATUS_PENDING,
            'next_attempt_at'     => now(),
        ]);
        if ($dispatch) {
            DeliverWebhookJob::dispatch($delivery->id)->afterCommit();
        }

        return $delivery;
    }

    public static function signature(string $secret, int $timestamp, string $body): string
    {
        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    /** One attempt. @return bool delivered */
    public function attempt(WebhookDelivery $delivery): bool
    {
        $endpoint = $delivery->endpoint;
        if (! $endpoint || ! $endpoint->active || $delivery->status !== WebhookDelivery::STATUS_PENDING) {
            return $delivery->status === WebhookDelivery::STATUS_DELIVERED;
        }
        $body = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $ts = now()->timestamp;
        $status = null;
        $excerpt = null;
        try {
            $request = Http::withHeaders([
                'Content-Type'       => 'application/json',
                'User-Agent'         => 'Autnyx-Webhooks/1',
                'X-Autnyx-Event'     => $delivery->event,
                'X-Autnyx-Delivery'  => $delivery->event_id,
                'X-Autnyx-Signature' => self::signature((string) $endpoint->secret, $ts, $body),
            ])->timeout(10)->withBody($body, 'application/json');
            $response = app(EgressGuard::class)->apply($request, (string) $endpoint->url)->post((string) $endpoint->url);
            $status = $response->status();
            $excerpt = Str::limit((string) $response->body(), 480, '…');
            $ok = $response->successful();
        } catch (\Throwable $e) {
            $excerpt = Str::limit($e->getMessage(), 480, '…');
            $ok = false;
        }

        $delivery->forceFill([
            'attempts'         => $delivery->attempts + 1,
            'response_status'  => $status,
            'response_excerpt' => $excerpt,
            'status'           => $ok ? WebhookDelivery::STATUS_DELIVERED : WebhookDelivery::STATUS_PENDING,
            'delivered_at'     => $ok ? now() : null,
        ])->save();

        if ($ok) {
            $endpoint->forceFill(['failure_streak' => 0, 'last_success_at' => now()])->save();
        }

        return $ok;
    }

    /** The last retry failed: mark the delivery failed and count it against the endpoint. */
    public function giveUp(WebhookDelivery $delivery): void
    {
        $delivery->forceFill(['status' => WebhookDelivery::STATUS_FAILED, 'next_attempt_at' => null])->save();
        $endpoint = $delivery->endpoint;
        if (! $endpoint) {
            return;
        }
        $streak = $endpoint->failure_streak + 1;
        $endpoint->forceFill(['failure_streak' => $streak, 'last_failure_at' => now()])->save();
        if ($streak >= WebhookEndpoint::DISABLE_AFTER && $endpoint->active) {
            $endpoint->forceFill(['active' => false,
                'disabled_reason' => "Switched off after {$streak} failed deliveries in a row (last: " . ($delivery->response_status ?: 'no response') . ').'])->save();
            try {
                AuditLog::create(['tenant_id' => $endpoint->tenant_id, 'event_type' => 'webhook_disabled',
                    'description' => "Webhook \"{$endpoint->name}\" switched off after {$streak} failed deliveries in a row."]);
            } catch (\Throwable) {
            }
            Log::warning('[webhooks] endpoint disabled', ['endpoint' => $endpoint->id, 'tenant_id' => $endpoint->tenant_id]);
        }
    }

    /**
     * finding.opened — new findings since each endpoint's cursor, at or above
     * its severity. A new endpoint starts from now (no flood of history).
     *
     * @return int deliveries queued
     */
    public function sweepFindings(int $tenantId): int
    {
        $queued = 0;
        $endpoints = WebhookEndpoint::where('tenant_id', $tenantId)->where('active', true)->get()
            ->filter(fn (WebhookEndpoint $e) => $e->wants('finding.opened'));
        foreach ($endpoints as $endpoint) {
            $maxId = (int) Anomaly::where('tenant_id', $tenantId)->max('id');
            if ($endpoint->last_finding_id === null) {
                $endpoint->forceFill(['last_finding_id' => $maxId])->save();
                continue;
            }
            $min = self::SEVERITY_RANK[$endpoint->min_severity] ?? 3;
            $sevs = array_keys(array_filter(self::SEVERITY_RANK, fn ($r) => $r >= $min));
            $new = Anomaly::where('tenant_id', $tenantId)->where('id', '>', $endpoint->last_finding_id)->where('id', '<=', $maxId)
                ->active()->whereIn('severity', $sevs)->orderBy('id')->limit(self::MAX_FINDINGS_PER_RUN)->get();
            foreach ($new as $a) {
                $this->queue($endpoint, 'finding.opened', $this->finding($a));
                $queued++;
            }
            $cursor = $new->count() >= self::MAX_FINDINGS_PER_RUN ? (int) $new->last()->id : $maxId;
            $endpoint->forceFill(['last_finding_id' => $cursor])->save();
        }

        return $queued;
    }

    // ── payloads (ids, codes and figures; no people's names or e-mail addresses) ──

    public function investigation(Investigation $i): array
    {
        return [
            'investigation_id' => $i->id,
            'title'            => $i->title,
            'status'           => $i->status,
            'priority'         => $i->priority,
            'sku'              => $i->primary_sku,
            'store_id'         => $i->primary_store_id,
            'store'            => $this->storeName($i->primary_store_id),
            'root_cause_rule'  => $i->root_cause_rule,
            'root_cause_tier'  => $i->root_cause_tier,
            'revenue_at_risk'  => $i->revenue_at_risk !== null ? (float) $i->revenue_at_risk : null,
            'capital_at_risk'  => $i->capital_at_risk !== null ? (float) $i->capital_at_risk : null,
            'opened_at'        => $i->opened_at?->toIso8601ZuluString(),
            'resolved_at'      => ($i->resolved_at ?? $i->closed_at)?->toIso8601ZuluString(),
            'url'              => $this->url($i->tenant_id, $i->id),
        ];
    }

    public function outcome(InvestigationOutcome $o): array
    {
        return [
            'outcome_id'        => $o->id,
            'investigation_id'  => $o->investigation_id,
            'outcome_state'     => $o->outcome_state,
            'measured_recovery' => $o->measured_recovery !== null ? (float) $o->measured_recovery : null,
            'measured_capital'  => $o->measured_capital !== null ? (float) $o->measured_capital : null,
            'window_start'      => $o->measurement_window_start ? (string) $o->measurement_window_start : null,
            'window_end'        => $o->measurement_window_end ? (string) $o->measurement_window_end : null,
            'url'               => $this->url($o->tenant_id, $o->investigation_id),
        ];
    }

    public function count(CycleCount $c): array
    {
        return [
            'count_id'       => $c->id,
            'store_id'       => $c->store_id,
            'store'          => $this->storeName($c->store_id),
            'sku'            => $c->sku,
            'reason'         => $c->reason,
            'system_qty'     => $c->system_qty !== null ? (float) $c->system_qty : null,
            'counted_qty'    => $c->counted_qty !== null ? (float) $c->counted_qty : null,
            'variance_qty'   => $c->variance_qty !== null ? (float) $c->variance_qty : null,
            'variance_value' => $c->variance_value !== null ? (float) $c->variance_value : null,
            'source'         => $c->count_source,
            'counted_at'     => $c->counted_at?->toIso8601ZuluString(),
            'investigation_id' => $c->investigation_id,
        ];
    }

    public function finding(Anomaly $a): array
    {
        $ctx = (array) ($a->context ?? []);

        return [
            'finding_id'       => $a->id,
            'rule_type'        => $a->rule_type,
            'rule'             => AnomalySetting::RULES[$a->rule_type]['label'] ?? $a->rule_type,
            'severity'         => $a->severity,
            'sku'              => $a->sku,
            'store_id'         => $a->store_id,
            'store'            => $this->storeName($a->store_id),
            'description'      => $a->description,
            'value'            => round(ValueModel::amount($ctx), 2),
            'value_type'       => $a->value_type ?? ValueModel::type((string) $a->rule_type, $ctx),
            'detected_at'      => $a->detected_at?->toIso8601ZuluString(),
            'investigation_id' => $a->investigation_id,
            'url'              => $a->investigation_id ? $this->url($a->tenant_id, $a->investigation_id) : null,
        ];
    }

    private function storeName(?int $id): ?string
    {
        return $id ? Store::whereKey($id)->value('name') : null;
    }

    private function url(int $tenantId, int $investigationId): ?string
    {
        try {
            return InvestigationResource::getUrl('investigate', ['record' => $investigationId, 'tenant' => Tenant::find($tenantId)], panel: 'admin');
        } catch (\Throwable) {
            return null;
        }
    }
}
