<?php

namespace App\Services\Platform;

use App\Models\PlatformEvent;
use Illuminate\Support\Collection;

/**
 * P1.2 — the append/read API for the platform event backbone. Producers append
 * facts through here; projections (detection, recovery, metrics) read them back.
 * Appends are idempotent when a source_ref is supplied, so live capture and
 * backfill never double-record the same fact.
 */
class EventStore
{
    /**
     * Append one fact. Fills occurred_at / recorded_at defaults. If source_ref is
     * given, the append is idempotent per tenant (returns the existing row rather
     * than creating a duplicate).
     *
     * @param  array<string,mixed>  $event
     */
    public function append(array $event): PlatformEvent
    {
        $event['occurred_at'] = $event['occurred_at'] ?? now();
        $event['recorded_at'] = $event['recorded_at'] ?? now();

        if (! empty($event['source_ref'])) {
            return PlatformEvent::firstOrCreate(
                ['tenant_id' => $event['tenant_id'], 'source_ref' => $event['source_ref']],
                $event,
            );
        }

        return PlatformEvent::create($event);
    }

    /**
     * Append many facts idempotently. Returns the number newly created.
     *
     * @param  array<int,array<string,mixed>>  $events
     */
    public function appendMany(array $events): int
    {
        $created = 0;
        foreach ($events as $event) {
            if ($this->append($event)->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * Replay the stream for a tenant over a valid-time window, oldest first —
     * optionally filtered to one event type. This is the reproducibility primitive.
     *
     * @return Collection<int,PlatformEvent>
     */
    public function replay(int $tenantId, $from, $to, ?string $eventType = null): Collection
    {
        return PlatformEvent::query()
            ->where('tenant_id', $tenantId)
            ->occurredBetween($from, $to)
            ->when($eventType, fn ($q) => $q->ofType($eventType))
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * The event history for one SKU (optionally one type), oldest first.
     *
     * @return Collection<int,PlatformEvent>
     */
    public function forSku(int $tenantId, string $sku, ?string $eventType = null): Collection
    {
        return PlatformEvent::query()
            ->where('tenant_id', $tenantId)
            ->forSku($sku)
            ->when($eventType, fn ($q) => $q->ofType($eventType))
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
    }
}
