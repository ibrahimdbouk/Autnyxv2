<?php

namespace App\Services\Webhooks;

use App\Models\CycleCount;
use App\Models\Investigation;
use App\Models\InvestigationOutcome;
use App\Models\WebhookEndpoint;

/**
 * W13 — the model changes that become webhook events. A tenant without an
 * active endpoint costs one cached lookup a minute; nothing else runs.
 * finding.opened is not here: findings are written in bulk by detection, so
 * they are swept after each night's run (webhooks:findings).
 */
class WebhookEvents
{
    /** @var array<int, array{0:bool, 1:int}> tenant => [has endpoints, checked at] */
    private static array $has = [];

    public static function register(): void
    {
        WebhookEndpoint::saved(fn (WebhookEndpoint $e) => self::forget($e->tenant_id));
        WebhookEndpoint::deleted(fn (WebhookEndpoint $e) => self::forget($e->tenant_id));
        Investigation::created(function (Investigation $i) {
            self::send($i->tenant_id, 'investigation.opened', fn (WebhookDispatcher $w) => $w->investigation($i));
        });
        Investigation::updated(function (Investigation $i) {
            if ($i->wasChanged('status') && in_array($i->status, [Investigation::STATUS_RESOLVED, Investigation::STATUS_CLOSED], true)
                && ! in_array($i->getOriginal('status'), [Investigation::STATUS_RESOLVED, Investigation::STATUS_CLOSED], true)) {
                self::send($i->tenant_id, 'investigation.resolved', fn (WebhookDispatcher $w) => $w->investigation($i));
            }
        });
        InvestigationOutcome::saved(function (InvestigationOutcome $o) {
            $measured = (float) $o->measured_recovery > 0 || (float) $o->measured_capital > 0;
            if ($measured && ($o->wasRecentlyCreated || $o->wasChanged(['measured_recovery', 'measured_capital']))) {
                self::send($o->tenant_id, 'outcome.measured', fn (WebhookDispatcher $w) => $w->outcome($o));
            }
        });
        CycleCount::updated(function (CycleCount $c) {
            if ($c->wasChanged('status') && $c->status === CycleCount::STATUS_COUNTED) {
                self::send($c->tenant_id, 'count.recorded', fn (WebhookDispatcher $w) => $w->count($c));
            }
        });
    }

    public static function forget(?int $tenantId = null): void
    {
        if ($tenantId === null) {
            self::$has = [];
        } else {
            unset(self::$has[$tenantId]);
        }
    }

    private static function send(?int $tenantId, string $event, \Closure $data): void
    {
        if (! $tenantId || ! self::hasEndpoints($tenantId)) {
            return;
        }
        try {
            $w = app(WebhookDispatcher::class);
            $w->emit($tenantId, $event, $data($w));
        } catch (\Throwable $e) {
            report($e);   // a webhook never blocks the change itself
        }
    }

    private static function hasEndpoints(int $tenantId): bool
    {
        [$has, $at] = self::$has[$tenantId] ?? [false, 0];
        if (time() - $at > 60) {
            $has = WebhookEndpoint::where('tenant_id', $tenantId)->where('active', true)->exists();
            self::$has[$tenantId] = [$has, time()];
        }

        return $has;
    }
}
