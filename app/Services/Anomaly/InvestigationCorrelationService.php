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

    /** Per-run cache of store id → name, so correlation doesn't Store::find per anomaly. */
    private array $storeNames = [];

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Correlate all un-linked open anomalies for a tenant.
     * Called by DetectAnomaliesCommand after detection runs.
     */
    public function correlateForTenant(int $tenantId): void
    {
        // Cache store names once — correlation would otherwise Store::find per
        // anomaly (title + entity recording), which dominates on large tenants.
        $this->storeNames = \App\Models\Store::where('tenant_id', $tenantId)->pluck('name', 'id')->all();

        $unlinked = Anomaly::where('tenant_id', $tenantId)
            ->whereNull('investigation_id')
            ->active()
            ->get();

        // Feature 6 — suppression is enforced HERE, at surfacing time. Suppressed
        // anomalies remain detected/recorded (history + FP learning preserved) but
        // are not correlated into investigations and do not notify.
        $suppressionService = App::make(\App\Services\Noise\SuppressionService::class);

        $correlated = 0;
        $suppressed = 0;
        $touched    = []; // investigation_id => Investigation, finalised once after the loop
        foreach ($unlinked as $anomaly) {
            try {
                $match = $suppressionService->matchFor($anomaly);
                if ($match) {
                    $suppressionService->recordMatch($match);
                    $suppressed++;
                    continue;
                }

                // Batch mode: link + record entities now, defer the expensive
                // finalisation (count, revenue, evidence) to one pass per investigation.
                $inv = $this->correlate($anomaly, false);
                $touched[$inv->id] = $inv;
                $correlated++;
            } catch (\Throwable $e) {
                Log::error("[M16/correlate] anomaly {$anomaly->id}: {$e->getMessage()}");
            }
        }

        // Finalise each touched investigation ONCE — was per-anomaly (O(anomalies)
        // evidence collections + full-anomaly-set revenue sums); now O(investigations).
        foreach ($touched as $investigation) {
            try {
                $investigation->syncAnomalyCount();
                $this->syncRevenueAtRisk($investigation);
                App::make(EvidenceCollectorService::class)->collectForInvestigation($investigation);
                // WP4.5: the deterministic cause, recorded as the evidence changes.
                App::make(RootCauseAnalysisService::class)->record($investigation);
                \App\Services\Investigation\TidyTail::resurfaceIfWarranted($investigation->fresh());
            } catch (\Throwable $e) {
                Log::error("[M16/finalise] investigation={$investigation->id}: {$e->getMessage()}");
            }
        }

        $this->storeNames = [];
        Log::info("[M16] Correlated {$correlated} anomalies ({$suppressed} suppressed) for tenant {$tenantId}");
    }

    /**
     * Correlate a single anomaly into an Investigation.
     * Idempotent: safe to call on anomalies that already have an investigation_id.
     */
    public function correlate(Anomaly $anomaly, bool $finalize = true): Investigation
    {
        // Already linked — return the existing investigation
        if ($anomaly->investigation_id) {
            return Investigation::find($anomaly->investigation_id);
        }

        $v2 = AnomalyDetectionService::rulesV2For((int) $anomaly->tenant_id);
        $investigation = ($v2 ? $this->findActiveBySubject($anomaly) : $this->findOpenInvestigation($anomaly))
            ?? $this->createInvestigation($anomaly, $v2 ? self::subjectKey($anomaly) : null);

        // Link the anomaly
        $anomaly->update(['investigation_id' => $investigation->id]);

        // WP4.5: an incident joining an auto-snoozed trend item brings it back.
        \App\Services\Investigation\TidyTail::resurfaceIfWarranted($investigation, (string) $anomaly->rule_type);

        // Record entity facts + escalate priority (cheap, must run per anomaly)
        $this->recordEntities($investigation, $anomaly);
        $this->escalatePriorityIfNeeded($investigation, $anomaly);

        // Finalisation (count, revenue, and the expensive evidence collection) is
        // deferred by the batch path (correlateForTenant) to one pass per
        // investigation. Standalone callers keep the immediate behaviour.
        if ($finalize) {
            $investigation->syncAnomalyCount();
            $this->syncRevenueAtRisk($investigation);
            try {
                App::make(EvidenceCollectorService::class)->collectForInvestigation($investigation);
            } catch (\Throwable $e) {
                Log::error("[M16/evidence] investigation={$investigation->id}: {$e->getMessage()}");
            }
        }

        return $investigation;
    }

    /** Resolve a store name from the per-run cache, falling back to a lookup. */
    private function storeName(?int $storeId): ?string
    {
        if (! $storeId) {
            return null;
        }
        if (array_key_exists($storeId, $this->storeNames)) {
            return $this->storeNames[$storeId];
        }

        return \App\Models\Store::find($storeId)?->name;
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
     * WP4.5 (audit H22) — what an anomaly is about, as an investigation key:
     * a shelf (SKU at a store), a SKU chain-wide, a PO / supplier / receipt
     * (the rule's own subject), a store, or — for tenant-wide checks — the rule.
     * Never one catch-all bucket for everything without a SKU.
     */
    public static function subjectKey(Anomaly $anomaly): string
    {
        $context = is_array($anomaly->context) ? $anomaly->context : [];
        $store   = $anomaly->store_id !== null ? '|store:' . $anomaly->store_id : '';

        if ($anomaly->sku !== null && trim($anomaly->sku) !== '') {
            return 'sku:' . trim($anomaly->sku) . $store;
        }
        if (! empty($context['subject'])) {
            return (string) $context['subject'] . $store;
        }
        if ($anomaly->store_id !== null) {
            return 'store:' . $anomaly->store_id . '|rule:' . $anomaly->rule_type;
        }

        return 'rule:' . $anomaly->rule_type;
    }

    /**
     * v2: the ACTIVE investigation (open / in progress, however long ago it
     * opened — the window extends while it is being worked) for the same
     * subject. A SKU's chain-level and store-level signals meet in one
     * investigation, so the same loss isn't split (and counted) twice.
     */
    private function findActiveBySubject(Anomaly $anomaly): ?Investigation
    {
        $active = fn () => Investigation::where('tenant_id', $anomaly->tenant_id)
            ->whereIn('status', [Investigation::STATUS_OPEN, Investigation::STATUS_IN_PROGRESS]);

        $exact = $active()->where('subject_key', self::subjectKey($anomaly))->orderByDesc('opened_at')->first();
        if ($exact || $anomaly->sku === null) {
            return $exact;
        }

        return $active()->where('primary_sku', $anomaly->sku)
            ->where(fn ($q) => $anomaly->store_id === null
                ? $q->whereNotNull('id')                 // chain-level joins any active same-SKU investigation
                : $q->whereNull('primary_store_id'))     // a store signal joins the chain-level one
            ->orderByRaw('primary_store_id IS NULL DESC')
            ->orderByDesc('opened_at')
            ->first();
    }

    /**
     * Create a new Investigation for an anomaly.
     */
    private function createInvestigation(Anomaly $anomaly, ?string $subjectKey = null): Investigation
    {
        $title    = $this->buildTitle($anomaly);
        $priority = Investigation::priorityFromSeverity($anomaly->severity);

        $investigation = Investigation::create([
            'subject_key'     => $subjectKey,
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

        if ($anomaly->store_id) {
            $name = $this->storeName($anomaly->store_id);
            if ($name) {
                $parts[] = "@ {$name}";
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
            $toInsert[] = [
                'investigation_id' => $investigation->id,
                'anomaly_id'       => $anomaly->id,
                'entity_type'      => InvestigationEntity::TYPE_STORE,
                'entity_key'       => $this->storeName($anomaly->store_id) ?? (string) $anomaly->store_id,
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
        // Single deterministic definition (WP1.1) — shared with the repair command.
        app(\App\Services\Investigation\DeterministicRevenueAtRisk::class)->sync($investigation);
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
