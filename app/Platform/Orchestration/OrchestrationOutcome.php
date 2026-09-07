<?php

namespace App\Platform\Orchestration;

/**
 * P4.8 — the result of orchestrating one decision: what the platform did
 * (executed / queued for approval / advised only / blocked), the effective
 * confidence after data-quality and memory adjustments, the autonomy level
 * applied, and the human-readable reasons. Fully explainable — every automated
 * action (or non-action) carries why.
 */
class OrchestrationOutcome
{
    public const MODE_EXECUTED = 'executed'; // dispatched automatically
    public const MODE_QUEUED   = 'queued';   // staged for human approval
    public const MODE_ADVISED  = 'advised';  // recommendation only, no action staged
    public const MODE_BLOCKED  = 'blocked';  // a guardrail forbade it

    /**
     * @param  array<int,string>  $reasons
     * @param  array<string,mixed>  $policy
     */
    public function __construct(
        public readonly string $mode,
        public readonly string $level,
        public readonly float $effectiveConfidence,
        public readonly array $reasons = [],
        public readonly array $policy = [],
        public readonly ?int $dispatchId = null,
        public readonly ?int $approvalId = null,
        public readonly ?int $caseId = null,
    ) {
    }

    public function executed(): bool
    {
        return $this->mode === self::MODE_EXECUTED;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'mode'                 => $this->mode,
            'level'                => $this->level,
            'effective_confidence' => $this->effectiveConfidence,
            'reasons'              => $this->reasons,
            'policy'               => $this->policy,
            'dispatch_id'          => $this->dispatchId,
            'approval_id'          => $this->approvalId,
            'case_id'              => $this->caseId,
        ];
    }
}
