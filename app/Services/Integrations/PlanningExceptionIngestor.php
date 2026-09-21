<?php

namespace App\Services\Integrations;

use App\Models\Anomaly;
use App\Models\AnomalySetting;
use App\Models\ApiConnection;
use App\Models\ApiFeed;
use App\Models\PlanningException;
use App\Models\Store;

/**
 * Routes a `planning_exception` API feed — the alerts a tenant's F&R system
 * (RELEX / Blue Yonder / Slimstock) raises itself — into Autnyx under a HYBRID
 * precedence model, so Autnyx acts on them without duplicating that tool:
 *
 *  - CORROBORATION (a type Autnyx also detects: forecast / demand / stockout /
 *    excess / safety-stock / order / supplier): the exception attaches to a
 *    matching OPEN Autnyx anomaly for the same (sku, store) as extra evidence
 *    (context.external_corroboration) and can raise its severity — it never
 *    creates a standalone investigation. Unmatched ones stay as a review signal
 *    (status = open), visible in the Planning Exceptions panel, off the queue.
 *  - FIRST-CLASS (a type Autnyx CANNOT derive from ERP+POS data: upstream
 *    supply / allocation / service-level / capacity / phase-in / promo risk):
 *    raises its OWN Autnyx anomaly (rule_type `planning_exception`), deduped per
 *    (sku, store). These live outside the detection scan, so the recovery
 *    reconciler never touches them — this ingestor owns their lifecycle.
 *
 * Each poll is treated as the CURRENT-OPEN snapshot for that source: an exception
 * that drops out of the feed is reconciled to resolved (and its first-class
 * anomaly resolved with it).
 *
 * Expected canonical headers (from field_map): sku, and any of store/location,
 * type (upstream type), category (optional explicit), severity, message,
 * occurred_at, external_ref. See claude/api-integration-library.md.
 */
class PlanningExceptionIngestor
{
    /** Normalised categories Autnyx CANNOT derive itself → raise a first-class anomaly. */
    private const FIRST_CLASS = ['supply_risk', 'allocation', 'service_level', 'capacity', 'phase_in', 'promo_plan'];

    private const RULE_FIRST_CLASS = 'planning_exception';

    /** @param iterable<int,array<string,mixed>> $rows */
    public function ingest(ApiConnection $connection, ApiFeed $feed, iterable $rows): int
    {
        $tenantId = (int) $connection->tenant_id;
        $source   = (string) ($connection->provider ?: 'planning');

        // Snapshot reconcile: everything currently open for this source is a
        // candidate to resolve unless this poll re-affirms it.
        $stale = PlanningException::where('tenant_id', $tenantId)
            ->where('source', $source)
            ->where('status', '!=', PlanningException::STATUS_RESOLVED)
            ->pluck('id')
            ->all();
        $stale = array_fill_keys($stale, true);

        $storeCache = [];
        $count      = 0;

        foreach ($rows as $row) {
            $sku = trim((string) ($row['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }

            $externalType = trim((string) ($row['type'] ?? $row['exception_type'] ?? ''));
            $category     = $this->categorise($row['category'] ?? null, $externalType, (string) ($row['message'] ?? ''));
            $disposition  = in_array($category, self::FIRST_CLASS, true)
                ? PlanningException::DISPOSITION_FIRST_CLASS
                : PlanningException::DISPOSITION_CORROBORATION;
            $severity     = $this->severity($row['severity'] ?? null);
            $storeId      = $this->resolveStore($tenantId, $row, $storeCache);
            $externalRef  = isset($row['external_ref']) ? trim((string) $row['external_ref']) : null;

            $exception = PlanningException::updateOrCreate(
                $this->naturalKey($tenantId, $source, $externalRef, $sku, $storeId, $externalType, $row['occurred_at'] ?? null),
                [
                    'sku'           => $sku,
                    'store_id'      => $storeId,
                    'external_type' => $externalType ?: null,
                    'category'      => $category,
                    'disposition'  => $disposition,
                    'severity'      => $severity,
                    'message'       => isset($row['message']) ? (string) $row['message'] : null,
                    'occurred_at'   => $this->toDate($row['occurred_at'] ?? null),
                    'payload'       => $this->payload($row),
                ],
            );

            unset($stale[$exception->id]); // re-affirmed by this poll

            $disposition === PlanningException::DISPOSITION_FIRST_CLASS
                ? $this->raiseFirstClass($tenantId, $exception)
                : $this->corroborate($tenantId, $exception);

            $count++;
        }

        // Anything not re-affirmed cleared upstream → resolve it (and its anomaly).
        if ($stale !== []) {
            $this->resolveCleared($tenantId, array_keys($stale));
        }

        return $count;
    }

    // ── Corroboration ────────────────────────────────────────────────────────

    /**
     * Attach to the most relevant OPEN Autnyx anomaly for the same (sku, store);
     * add it as evidence and raise severity to at least the exception's. If none
     * exists, leave it parked (status = open) as a review signal — never a new
     * investigation.
     */
    private function corroborate(int $tenantId, PlanningException $exception): void
    {
        $anomaly = Anomaly::query()
            ->where('tenant_id', $tenantId)
            ->where('sku', $exception->sku)
            ->whereNull('dismissed_at')
            ->where('lifecycle_state', '!=', Anomaly::LIFECYCLE_RESOLVED)
            ->where(function ($q) use ($exception) {
                $q->where('store_id', $exception->store_id);
                if ($exception->store_id !== null) {
                    $q->orWhereNull('store_id'); // a chain-level anomaly still covers a store exception
                }
            })
            ->orderByRaw("CASE severity WHEN 'high' THEN 3 WHEN 'medium' THEN 2 ELSE 1 END DESC")
            ->orderByDesc('detected_at')
            ->first();

        if ($anomaly === null) {
            // Parked review signal — dropping any stale anomaly link it may have had.
            $exception->forceFill([
                'status'             => PlanningException::STATUS_OPEN,
                'matched_anomaly_id' => null,
                'anomaly_id'         => null,
            ])->save();

            return;
        }

        $context = is_array($anomaly->context) ? $anomaly->context : [];
        $list    = $context['external_corroboration'] ?? [];

        // De-dupe the evidence entry by (source, external_type).
        $list = array_values(array_filter($list, fn ($e) => ! (
            ($e['source'] ?? null) === $exception->source && ($e['type'] ?? null) === ($exception->external_type ?? '')
        )));
        $list[] = [
            'source'      => $exception->source,
            'type'        => $exception->external_type,
            'category'    => $exception->category,
            'severity'    => $exception->severity,
            'occurred_at' => optional($exception->occurred_at)->toDateString(),
            'message'     => $exception->message,
        ];
        $context['external_corroboration'] = $list;

        $anomaly->context  = $context;
        $anomaly->severity = $this->maxSeverity($anomaly->severity, $exception->severity);
        $anomaly->save();

        $exception->forceFill([
            'status'             => PlanningException::STATUS_MATCHED,
            'matched_anomaly_id' => $anomaly->id,
            'anomaly_id'         => null,
        ])->save();
    }

    // ── First-class ──────────────────────────────────────────────────────────

    /**
     * Raise (or refresh) an Autnyx anomaly for an upstream-only exception. Deduped
     * per (tenant, rule_type, sku, store) like flag(), but managed here — it is not
     * a scan rule, so the recovery reconciler leaves it alone.
     */
    private function raiseFirstClass(int $tenantId, PlanningException $exception): void
    {
        // Respect the tenant toggle if the rule is explicitly disabled.
        $setting = AnomalySetting::where('tenant_id', $tenantId)
            ->where('rule_type', self::RULE_FIRST_CLASS)->first();
        if ($setting && ! $setting->enabled) {
            $exception->forceFill(['status' => PlanningException::STATUS_OPEN, 'anomaly_id' => null])->save();

            return;
        }

        $severity    = $this->maxSeverity(Anomaly::SEVERITY_LOW, $exception->severity);
        $label       = $this->categoryLabel($exception->category);
        $where       = $exception->store_id !== null ? " at store {$exception->store_id}" : ' chain-wide';
        $description = "{$exception->source} flagged {$label} for SKU {$exception->sku}{$where}"
            . ($exception->message ? " — {$exception->message}" : '.');
        $context = [
            'model'         => 'ingested_exception',
            'source'        => $exception->source,
            'category'      => $exception->category,
            'external_type' => $exception->external_type,
            'occurred_at'   => optional($exception->occurred_at)->toDateString(),
            'upstream_only' => true,
        ];

        $query = Anomaly::where('tenant_id', $tenantId)
            ->where('rule_type', self::RULE_FIRST_CLASS)
            ->where('sku', $exception->sku)
            ->whereNull('dismissed_at')
            ->where('lifecycle_state', '!=', Anomaly::LIFECYCLE_RESOLVED);
        $exception->store_id !== null
            ? $query->where('store_id', $exception->store_id)
            : $query->whereNull('store_id');
        $anomaly = $query->first();

        if ($anomaly) {
            $anomaly->update([
                'severity'    => $severity,
                'description' => $description,
                'context'     => $context,
            ]);
        } else {
            $anomaly = Anomaly::create([
                'tenant_id'   => $tenantId,
                'rule_type'   => self::RULE_FIRST_CLASS,
                'severity'    => $severity,
                'sku'         => $exception->sku,
                'store_id'    => $exception->store_id,
                'description' => $description,
                'context'     => $context,
                'detected_at' => now(),
            ]);
        }

        $exception->forceFill([
            'status'             => PlanningException::STATUS_MATCHED,
            'anomaly_id'         => $anomaly->id,
            'matched_anomaly_id' => null,
        ])->save();
    }

    /** Cleared upstream: resolve the exception, and resolve any first-class anomaly it raised. */
    private function resolveCleared(int $tenantId, array $exceptionIds): void
    {
        $rows = PlanningException::where('tenant_id', $tenantId)->whereIn('id', $exceptionIds)->get();

        foreach ($rows as $exception) {
            if ($exception->anomaly_id !== null) {
                Anomaly::where('id', $exception->anomaly_id)
                    ->where('rule_type', self::RULE_FIRST_CLASS)
                    ->whereNull('dismissed_at')
                    ->where('lifecycle_state', '!=', Anomaly::LIFECYCLE_RESOLVED)
                    ->update(['lifecycle_state' => Anomaly::LIFECYCLE_RESOLVED, 'resolved_at' => now()]);
            }
            // Corroboration: the matched anomaly's own lifecycle is governed by
            // Autnyx's detection, so we leave it — only the exception is closed.
            $exception->update(['status' => PlanningException::STATUS_RESOLVED]);
        }
    }

    // ── Classification & helpers ───────────────────────────────────────────────

    private function categorise($explicit, string $externalType, string $message): string
    {
        $explicit = is_string($explicit) ? strtolower(trim($explicit)) : '';
        $known = ['supply_risk', 'allocation', 'service_level', 'capacity', 'phase_in', 'promo_plan',
            'forecast', 'demand', 'stockout', 'excess', 'safety_stock', 'order', 'supplier', 'other'];
        if (in_array($explicit, $known, true)) {
            return $explicit;
        }

        $hay = strtolower($externalType . ' ' . $message);
        return match (true) {
            str_contains($hay, 'alloc')                                              => 'allocation',
            str_contains($hay, 'shortage') || str_contains($hay, 'supply') ||
                str_contains($hay, 'unavailab') || str_contains($hay, 'constrain')   => 'supply_risk',
            str_contains($hay, 'service level') || str_contains($hay, 'sla') ||
                str_contains($hay, 'fill target') || str_contains($hay, 'service')    => 'service_level',
            str_contains($hay, 'capacity') || str_contains($hay, 'warehouse')         => 'capacity',
            str_contains($hay, 'phase') || str_contains($hay, 'new product') ||
                str_contains($hay, 'introduction') || str_contains($hay, 'npi')       => 'phase_in',
            str_contains($hay, 'promo')                                               => 'promo_plan',
            str_contains($hay, 'forecast') || str_contains($hay, 'demand')            => 'forecast',
            str_contains($hay, 'stockout') || str_contains($hay, 'out of stock') ||
                str_contains($hay, 'oos')                                             => 'stockout',
            str_contains($hay, 'excess') || str_contains($hay, 'overstock') ||
                str_contains($hay, 'surplus')                                         => 'excess',
            str_contains($hay, 'safety')                                              => 'safety_stock',
            str_contains($hay, 'replenish') || str_contains($hay, 'order')            => 'order',
            str_contains($hay, 'supplier') || str_contains($hay, 'vendor')            => 'supplier',
            default                                                                   => 'other',
        };
    }

    private function categoryLabel(string $category): string
    {
        return match ($category) {
            'supply_risk'   => 'a supply risk',
            'allocation'    => 'an allocation shortfall',
            'service_level' => 'a service-level breach',
            'capacity'      => 'a capacity constraint',
            'phase_in'      => 'a phase-in / new-product exception',
            'promo_plan'    => 'a promotion-planning exception',
            default         => 'a planning exception',
        };
    }

    private function severity($value): string
    {
        $v = strtolower(trim((string) $value));
        return match (true) {
            in_array($v, ['high', 'critical', 'severe', '3'], true)      => Anomaly::SEVERITY_HIGH,
            in_array($v, ['low', 'info', 'informational', '1'], true)    => Anomaly::SEVERITY_LOW,
            default                                                      => Anomaly::SEVERITY_MEDIUM,
        };
    }

    private function maxSeverity(string $a, string $b): string
    {
        $rank = ['low' => 1, 'medium' => 2, 'high' => 3];
        return ($rank[$b] ?? 2) > ($rank[$a] ?? 2) ? $b : $a;
    }

    /** Natural key for idempotent upsert: prefer the upstream ref, else the shape of the alert. */
    private function naturalKey(int $tenantId, string $source, ?string $externalRef, string $sku, ?int $storeId, string $externalType, $occurredAt): array
    {
        if ($externalRef !== null && $externalRef !== '') {
            return ['tenant_id' => $tenantId, 'source' => $source, 'external_ref' => $externalRef];
        }

        return [
            'tenant_id'     => $tenantId,
            'source'        => $source,
            'external_ref'  => null,
            'sku'           => $sku,
            'store_id'      => $storeId,
            'external_type' => $externalType ?: null,
            'occurred_at'   => $this->toDate($occurredAt),
        ];
    }

    private function resolveStore(int $tenantId, array $row, array &$cache): ?int
    {
        $ref = trim((string) ($row['store'] ?? $row['location'] ?? $row['store_code'] ?? ''));
        if ($ref === '') {
            return null;
        }
        if (array_key_exists($ref, $cache)) {
            return $cache[$ref];
        }

        $store = Store::where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->where('code', $ref)->orWhere('name', $ref))
            ->first();

        return $cache[$ref] = $store?->id;
    }

    private function payload(array $row): ?array
    {
        $known = ['sku', 'store', 'location', 'store_code', 'type', 'exception_type', 'category',
            'severity', 'message', 'occurred_at', 'external_ref'];
        $extra = array_diff_key($row, array_fill_keys($known, true));

        return $extra === [] ? null : $extra;
    }

    private function toDate($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return \Carbon\Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
