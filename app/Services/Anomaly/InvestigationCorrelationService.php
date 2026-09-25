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

    /**
     * WP6.3 (audit H31) — per-run caches for the batch path, so correlating N
     * anomalies costs O(N) inserts, not O(N) lookups of the same tenant state.
     * null = not in a batch (standalone callers query as before).
     *
     * @var array{v2:bool,team:?int,subject:array<string,int>,sku:array<string,array<int,array{id:int,store:?int}>>,models:array<int,Investigation>}|null
     */
    private ?array $run = null;

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
        $this->run = $this->loadRunState($tenantId);

        // Streamed in id order (a large first run can have tens of thousands).
        $unlinked = Anomaly::where('tenant_id', $tenantId)
            ->whereNull('investigation_id')
            ->active()
            ->lazyById(1000);

        // Feature 6 — suppression is enforced HERE, at surfacing time. Suppressed
        // anomalies remain detected/recorded (history + FP learning preserved) but
        // are not correlated into investigations and do not notify.
        $suppressionService = App::make(\App\Services\Noise\SuppressionService::class);

        $correlated = 0;
        $suppressed = 0;
        $touched    = []; // investigation_id => Investigation, finalised once after the loop
        // WP6.3: a first run on a big tenant can have tens of thousands of new
        // anomalies. Correlation stops taking new ones after its time budget
        // (the rest stay unlinked and the next run continues), so the nightly
        // detection step always finishes inside its job timeout.
        $deadline = microtime(true) + (int) config('detection.correlation_budget_seconds', 1200);
        $deferred = false;
        foreach ($unlinked as $anomaly) {
            if (microtime(true) > $deadline) {
                $deferred = true;
                break;
            }
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
        $this->run = null;
        if ($deferred) {
            Log::warning('[M16] correlation budget reached — the remaining anomalies are linked on the next run', ['tenant_id' => $tenantId]);
        }
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

        $v2 = $this->run['v2'] ?? AnomalyDetectionService::rulesV2For((int) $anomaly->tenant_id);
        $investigation = ($v2 ? $this->findActiveBySubject($anomaly) : $this->findOpenInvestigation($anomaly))
            ?? $this->createInvestigation($anomaly, $v2 ? self::subjectKey($anomaly) : null);
        if ($this->run !== null) {
            $this->remember($investigation);
        }

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
        if ($this->run !== null) {
            return $this->findActiveBySubjectCached($anomaly);
        }

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

    /** Active investigations of the tenant, indexed the way findActiveBySubject() matches. */
    private function loadRunState(int $tenantId): array
    {
        $state = [
            'v2'     => AnomalyDetectionService::rulesV2For($tenantId),
            'team'   => \App\Models\Team::where('tenant_id', $tenantId)->where('is_default', true)->value('id'),
            'subject' => [], 'sku' => [], 'models' => [],
        ];
        Investigation::where('tenant_id', $tenantId)
            ->whereIn('status', [Investigation::STATUS_OPEN, Investigation::STATUS_IN_PROGRESS])
            ->orderBy('opened_at')->orderBy('id')   // later ones overwrite → the most recently opened wins
            ->select(['id', 'subject_key', 'primary_sku', 'primary_store_id'])
            ->cursor()
            ->each(function ($i) use (&$state) {
                if ($i->subject_key !== null) {
                    $state['subject'][$i->subject_key] = (int) $i->id;
                }
                if ($i->primary_sku !== null) {
                    $state['sku'][$i->primary_sku][] = ['id' => (int) $i->id, 'store' => $i->primary_store_id !== null ? (int) $i->primary_store_id : null];
                }
            });

        return $state;
    }

    private function remember(Investigation $investigation): void
    {
        $id = (int) $investigation->id;
        $this->run['models'][$id] = $investigation;
        if ($investigation->subject_key !== null) {
            $this->run['subject'][$investigation->subject_key] = $id;
        }
        if ($investigation->primary_sku !== null
            && ! in_array($id, array_column($this->run['sku'][$investigation->primary_sku] ?? [], 'id'), true)) {
            $this->run['sku'][$investigation->primary_sku][] = ['id' => $id, 'store' => $investigation->primary_store_id];
        }
    }

    /** Same rules as findActiveBySubject(), answered from the per-run index. */
    private function findActiveBySubjectCached(Anomaly $anomaly): ?Investigation
    {
        $id = $this->run['subject'][self::subjectKey($anomaly)] ?? null;
        if ($id === null && $anomaly->sku !== null) {
            $candidates = $this->run['sku'][$anomaly->sku] ?? [];
            if ($anomaly->store_id !== null) {
                $candidates = array_filter($candidates, fn ($c) => $c['store'] === null);   // a store signal joins the chain-level one
            }
            // chain-level investigations first, then the most recently opened (later in the list)
            $best = null;
            foreach ($candidates as $c) {
                if ($best === null || ($c['store'] === null) >= ($best['store'] === null)) {
                    $best = $c;
                }
            }
            $id = $best['id'] ?? null;
        }
        if ($id === null) {
            return null;
        }

        return $this->run['models'][$id] ??= Investigation::find($id);
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
        $defaultTeamId = $this->run !== null ? $this->run['team']
            : \App\Models\Team::where('tenant_id', $anomaly->tenant_id)->where('is_default', true)->value('id');

        if ($defaultTeamId) {
            $investigation->update([
                'assigned_team_id' => $defaultTeamId,
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

        // One statement; duplicates skipped by the unique key
        // (investigation_id, anomaly_id, entity_type, entity_key). WP6.3.
        $now = now();
        InvestigationEntity::insertOrIgnore(array_map(
            fn ($row) => $row + ['created_at' => $now, 'updated_at' => $now],
            $toInsert
        ));
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
