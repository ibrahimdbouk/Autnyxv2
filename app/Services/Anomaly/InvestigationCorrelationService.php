<?php

namespace App\Services\Anomaly;

use App\Models\Anomaly;
use App\Models\AnomalySetting;
use App\Models\Investigation;
use App\Models\InvestigationEntity;
use Carbon\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;

/**
 * Correlates anomalies into Investigations.
 *
 * Correlation logic (in order of precedence):
 *
 *   1. Anomaly already has an investigation_id → skip (already correlated).
 *   2. There is an open (open | in_progress) Investigation for the same
 *      tenant + SKU opened within the last CORRELATION_WINDOW_DAYS → attach.
 *   3. No matching Investigation found → create a new one.
 *
 * After attaching or creating, the anomaly's entity facts are recorded in
 * investigation_entities for the Evidence layer (M17).
 */
class InvestigationCorrelationService
{
    /** Days within which a same-SKU anomaly is absorbed into an open investigation */
    const CORRELATION_WINDOW_DAYS = 7;

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Correlate all un-linked open anomalies for a tenant.
     * Called by DetectAnomaliesCommand after detection runs.
     */
    public function correlateForTenant(int $tenantId): void
    {
        $unlinked = Anomaly::where('tenant_id', $tenantId)
            ->whereNull('investigation_id')
            ->whereNull('dismissed_at')
            ->get();

        // Feature 6 — suppression is enforced HERE, at surfacing time. Suppressed
        // anomalies remain detected/recorded (history + FP learning preserved) but
        // are not correlated into investigations and do not notify.
        $suppressionService = App::make(\App\Services\Noise\SuppressionService::class);

        $correlated = 0;
        $suppressed = 0;
        foreach ($unlinked as $anomaly) {
            try {
                $match = $suppressionService->matchFor($anomaly);
                if ($match) {
                    $suppressionService->recordMatch($match);
                    $suppressed++;
                    continue;
                }

                $this->correlate($anomaly);
                $correlated++;
            } catch (\Throwable $e) {
                Log::error("[M16/correlate] anomaly {$anomaly->id}: {$e->getMessage()}");
            }
        }

        Log::info("[M16] Correlated {$correlated} anomalies ({$suppressed} suppressed) for tenant {$tenantId}");
    }

    /**
     * Correlate a single anomaly into an Investigation.
     * Idempotent: safe to call on anomalies that already have an investigation_id.
     */
    public function correlate(Anomaly $anomaly): Investigation
    {
        // Already linked — return the existing investigation
        if ($anomaly->investigation_id) {
            return Investigation::find($anomaly->investigation_id);
        }

        $investigation = $this->findOpenInvestigation($anomaly)
            ?? $this->createInvestigation($anomaly);

        // Link the anomaly
        $anomaly->update(['investigation_id' => $investigation->id]);

        // Record entity facts
        $this->recordEntities($investigation, $anomaly);

        // Sync count and escalate priority if this anomaly is more severe
        $investigation->syncAnomalyCount();
        $this->syncRevenueAtRisk($investigation);
        $this->escalatePriorityIfNeeded($investigation, $anomaly);

        // Collect evidence for this anomaly (M17)
        try {
            App::make(EvidenceCollectorService::class)->collectForInvestigation($investigation);
        } catch (\Throwable $e) {
            Log::error("[M16/evidence] investigation={$investigation->id}: {$e->getMessage()}");
        }

        return $investigation;
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Find an open investigation that this anomaly should belong to.
     *
     * Matches on:
     *   - Same tenant
     *   - Same primary_sku (or both null)
     *   - Status open or in_progress
     *   - Opened within CORRELATION_WINDOW_DAYS
     */
    private function findOpenInvestigation(Anomaly $anomaly): ?Investigation
    {
        $since = Carbon::now()->subDays(self::CORRELATION_WINDOW_DAYS);

        $query = Investigation::where('tenant_id', $anomaly->tenant_id)
            ->whereIn('status', [Investigation::STATUS_OPEN, Investigation::STATUS_IN_PROGRESS])
            ->where('opened_at', '>=', $since);

        if ($anomaly->sku) {
            $query->where('primary_sku', $anomaly->sku);
        } else {
            $query->whereNull('primary_sku');
        }

        // Prefer the most recently opened matching investigation
        return $query->orderByDesc('opened_at')->first();
    }

    /**
     * Create a new Investigation for an anomaly.
     */
    private function createInvestigation(Anomaly $anomaly): Investigation
    {
        $title    = $this->buildTitle($anomaly);
        $priority = Investigation::priorityFromSeverity($anomaly->severity);

        $investigation = Investigation::create([
            'tenant_id'       => $anomaly->tenant_id,
            'title'           => $title,
            'status'          => Investigation::STATUS_OPEN,
            'priority'        => $priority,
            'primary_sku'     => $anomaly->sku,
            'primary_store_id'=> $anomaly->store_id,
            'opened_at'       => now(),
            'anomaly_count'   => 0,
        ]);

        // Auto-assign to the tenant's default team, if one exists
        $defaultTeam = \App\Models\Team::where('tenant_id', $anomaly->tenant_id)
            ->where('is_default', true)
            ->first();

        if ($defaultTeam) {
            $investigation->update([
                'assigned_team_id' => $defaultTeam->id,
                'assigned_at'      => now(),
            ]);
        }

        Log::info("[M16] Created Investigation #{$investigation->id} '{$title}' for tenant {$anomaly->tenant_id}");

        return $investigation;
    }

    /**
     * Build a human-readable title for a new investigation from its first anomaly.
     */
    private function buildTitle(Anomaly $anomaly): string
    {
        $ruleLabel = AnomalySetting::RULES[$anomaly->rule_type]['label']
            ?? ucwords(str_replace('_', ' ', $anomaly->rule_type));

        $parts = [$ruleLabel];

        if ($anomaly->sku) {
            $parts[] = "SKU {$anomaly->sku}";
        }

        if ($anomaly->store_id && $anomaly->relationLoaded('store') && $anomaly->store) {
            $parts[] = "@ {$anomaly->store->name}";
        } elseif ($anomaly->store_id) {
            // Lazy-load store name without eager loading the whole query
            $store = \App\Models\Store::find($anomaly->store_id);
            if ($store) {
                $parts[] = "@ {$store->name}";
            }
        }

        return implode(' — ', $parts);
    }

    /**
     * Record the anomaly's entities (SKU, store, rule) into investigation_entities.
     */
    private function recordEntities(Investigation $investigation, Anomaly $anomaly): void
    {
        $toInsert = [];

        // Rule entity — always recorded
        $toInsert[] = [
            'investigation_id' => $investigation->id,
            'anomaly_id'       => $anomaly->id,
            'entity_type'      => InvestigationEntity::TYPE_RULE,
            'entity_key'       => $anomaly->rule_type,
            'store_id'         => null,
        ];

        // SKU entity
        if ($anomaly->sku) {
            $toInsert[] = [
                'investigation_id' => $investigation->id,
                'anomaly_id'       => $anomaly->id,
                'entity_type'      => InvestigationEntity::TYPE_SKU,
                'entity_key'       => $anomaly->sku,
                'store_id'         => null,
            ];
        }

        // Store entity
        if ($anomaly->store_id) {
            $store = \App\Models\Store::find($anomaly->store_id);
            $toInsert[] = [
                'investigation_id' => $investigation->id,
                'anomaly_id'       => $anomaly->id,
                'entity_type'      => InvestigationEntity::TYPE_STORE,
                'entity_key'       => $store?->name ?? (string) $anomaly->store_id,
                'store_id'         => $anomaly->store_id,
            ];
        }

        foreach ($toInsert as $row) {
            // Skip duplicates gracefully — unique key: (investigation_id, anomaly_id, entity_type, entity_key)
            InvestigationEntity::firstOrCreate(
                [
                    'investigation_id' => $row['investigation_id'],
                    'anomaly_id'       => $row['anomaly_id'],
                    'entity_type'      => $row['entity_type'],
                    'entity_key'       => $row['entity_key'],
                ],
                ['store_id' => $row['store_id']]
            );
        }
    }

    /**
     * If the incoming anomaly severity maps to a higher priority than the
     * investigation currently holds, escalate it.
     */
    /**
     * Recompute the investigation's revenue-at-risk as the sum of its member
     * anomalies' estimated revenue impact. This is what lets the investigations
     * list rank by money and surface the handful that actually matter.
     */
    private function syncRevenueAtRisk(Investigation $investigation): void
    {
        $total = 0.0;
        foreach ($investigation->anomalies()->get(['context']) as $a) {
            $total += (float) ($a->context['revenue_impact'] ?? 0);
        }

        $investigation->update(['revenue_at_risk' => round($total, 2)]);
    }

    private function escalatePriorityIfNeeded(Investigation $investigation, Anomaly $anomaly): void
    {
        $priorityOrder = [
            Investigation::PRIORITY_LOW      => 1,
            Investigation::PRIORITY_MEDIUM   => 2,
            Investigation::PRIORITY_HIGH     => 3,
            Investigation::PRIORITY_CRITICAL => 4,
        ];

        $incomingPriority = Investigation::priorityFromSeverity($anomaly->severity);
        $current          = $priorityOrder[$investigation->priority] ?? 0;
        $incoming         = $priorityOrder[$incomingPriority] ?? 0;

        if ($incoming > $current) {
            $investigation->update(['priority' => $incomingPriority]);
        }
    }
}
