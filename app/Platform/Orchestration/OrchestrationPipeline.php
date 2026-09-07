<?php

namespace App\Platform\Orchestration;

use App\Models\ApprovalQueueItem;
use App\Models\AutonomyPolicy;
use App\Models\DecisionCase;
use App\Platform\Integration\ActionIntent;
use App\Platform\Integration\OutboundDispatcher;
use App\Platform\Memory\CaseRecorder;
use App\Platform\Memory\CaseRetrieval;
use App\Platform\Policy\PolicyEngine;
use App\Platform\Recommendation\Recommendation;
use App\Platform\Trust\DataConfidence;

/**
 * P4.8 — the orchestration engine: run one scored recommendation through the whole
 * safe-execution loop and act on it at the tenant's chosen autonomy level. It
 * composes the platform primitives built earlier —
 *   confidence discount (P4.2) → memory consult (P4.3) → guardrail gate (P4.1)
 *   → autonomy routing → execute (P2.1) → remember (P4.3)
 * — and returns a fully-explained {@see OrchestrationOutcome}.
 *
 * App-neutral by construction: it only knows platform types (Recommendation,
 * ActionIntent, DecisionCase). An app (e.g. Root-Cause) turns its own signal into
 * a Recommendation and calls orchestrate(); the platform never reaches into the app.
 */
class OrchestrationPipeline
{
    public function __construct(
        private readonly AutonomyRegistry $autonomy,
        private readonly DataConfidence $dataConfidence,
        private readonly CaseRetrieval $memory,
        private readonly PolicyEngine $policy,
        private readonly OutboundDispatcher $dispatcher,
        private readonly CaseRecorder $recorder,
    ) {
    }

    /**
     * @param  array<string,mixed>  $situation  numeric feature vector for memory retrieval
     * @param  array<string,mixed>  $evidence
     * @param  array<int,string>    $dataFeeds  feed keys this recommendation depends on (P4.2)
     */
    public function orchestrate(
        Recommendation $rec,
        array $situation = [],
        array $evidence = [],
        array $dataFeeds = [],
    ): OrchestrationOutcome {
        $tenantId = $rec->tenantId;
        $reasons = [];

        // 1. Discount confidence for data quality (P4.2).
        $adjustment = $this->dataConfidence->adjust($rec->confidence, $tenantId, $dataFeeds);
        $effective = $adjustment->adjusted;
        foreach ($adjustment->reasons as $r) {
            $reasons[] = "data: {$r}";
        }

        // 2. Consult decision memory (P4.3): blend in how similar cases actually turned out.
        $mem = $this->memory->successRate($tenantId, $situation, $rec->intentType);
        if ($mem['n'] > 0 && $mem['success_rate'] !== null) {
            $effective = round(($effective + $mem['success_rate']) / 2, 4);
            $reasons[] = "memory: {$mem['n']} similar cases, past success {$mem['success_rate']}";
        }

        // 3. Build the canonical action-intent carrying the effective confidence.
        $intent = new ActionIntent(
            tenantId: $tenantId,
            intentType: $rec->intentType,
            sku: $rec->sku,
            storeId: $rec->storeId,
            quantity: $rec->quantity,
            targetDate: $rec->targetDate,
            rationale: $rec->rationale,
            expectedValue: $rec->expectedValue,
            objective: $rec->objective,
            source: $rec->source,
            metadata: ['confidence' => $effective, 'risk' => $rec->risk],
        );

        // 4. Guardrail gate (P4.1).
        $decision = $this->policy->decideForIntent($intent);

        // 5. Resolve the tenant's autonomy level.
        ['level' => $level, 'min_confidence' => $minConfidence] = $this->autonomy->resolve($tenantId, $rec->intentType);

        // 6. Route.
        $mode = null;
        $caseDecision = DecisionCase::DECISION_DEFERRED;
        $dispatchId = null;
        $approvalId = null;

        if ($decision->blocked()) {
            $mode = OrchestrationOutcome::MODE_BLOCKED;
            $caseDecision = DecisionCase::DECISION_REJECTED;
            foreach ($decision->blockingReasons() as $r) {
                $reasons[] = "guardrail: {$r}";
            }
        } elseif ($level === AutonomyPolicy::LEVEL_ADVISE) {
            $mode = OrchestrationOutcome::MODE_ADVISED;
        } elseif ($level === AutonomyPolicy::LEVEL_AUTO
            && $decision->allowed()
            && $effective >= $minConfidence
        ) {
            $dispatch = $this->dispatcher->dispatch($intent);
            $dispatchId = $dispatch->id;
            $mode = OrchestrationOutcome::MODE_EXECUTED;
            $caseDecision = DecisionCase::DECISION_ADOPTED;
            $reasons[] = "auto-executed: confidence {$effective} ≥ threshold {$minConfidence}";
        } else {
            // approve level, or auto falling back to a human.
            $approvalId = $this->queue($intent, $rec, $reasons)->id;
            $mode = OrchestrationOutcome::MODE_QUEUED;
            if ($level === AutonomyPolicy::LEVEL_AUTO) {
                $reasons[] = $effective < $minConfidence
                    ? "auto→approval: confidence {$effective} below threshold {$minConfidence}"
                    : "auto→approval: guardrail requires approval";
            }
        }

        // 7. Remember the arc (P4.3).
        $case = $this->recorder->record(
            $rec,
            $situation,
            $evidence,
            $caseDecision,
            $dispatchId !== null ? "dispatch:{$dispatchId}" : null,
        );

        return new OrchestrationOutcome(
            mode: $mode,
            level: $level,
            effectiveConfidence: $effective,
            reasons: $reasons,
            policy: $decision->toArray(),
            dispatchId: $dispatchId,
            approvalId: $approvalId,
            caseId: $case->id,
        );
    }

    /**
     * @param  array<int,string>  $reasons
     */
    private function queue(ActionIntent $intent, Recommendation $rec, array $reasons): ApprovalQueueItem
    {
        return ApprovalQueueItem::create([
            'tenant_id'       => $rec->tenantId,
            'intent_type'     => $rec->intentType,
            'source'          => $rec->source,
            'request_payload' => $intent->toArray(),
            'reason'          => implode('; ', $reasons) ?: null,
            'status'          => ApprovalQueueItem::STATUS_PENDING,
        ]);
    }
}
