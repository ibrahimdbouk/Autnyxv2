<?php

namespace App\Platform\Governance;

use App\Models\ContractViolation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * P3.4 — evaluate one ingestion against its data contract and record any breaches.
 * The caller passes a snapshot of what arrived (columns present, row count, when it
 * was generated); this checks it against the contract's required columns, minimum
 * rows, emptiness, and freshness SLA, and writes a {@see ContractViolation} for
 * each breach. Returns the violations it recorded (empty = the feed met its
 * contract). No contract for the feed → nothing to enforce → empty.
 */
class ContractEvaluator
{
    public function __construct(private readonly ContractRegistry $contracts)
    {
    }

    /**
     * @param  array{columns?:array<int,string>,row_count?:int,generated_at?:mixed}  $snapshot
     * @return Collection<int,ContractViolation>
     */
    public function evaluate(int $tenantId, string $feedKey, array $snapshot): Collection
    {
        $contract = $this->contracts->get($tenantId, $feedKey);
        $violations = collect();

        if (! $contract) {
            return $violations;
        }

        $columns  = $snapshot['columns'] ?? [];
        $rowCount = (int) ($snapshot['row_count'] ?? 0);

        // Missing required columns.
        $missing = array_values(array_diff($contract->required_columns ?? [], $columns));
        if ($missing !== []) {
            $violations->push($this->record($contract, ContractViolation::KIND_MISSING_COLUMNS,
                'Missing columns: ' . implode(', ', $missing)));
        }

        // Empty feed.
        if ($rowCount === 0) {
            $violations->push($this->record($contract, ContractViolation::KIND_EMPTY, 'Feed delivered 0 rows.'));
        } elseif ($contract->min_rows !== null && $rowCount < $contract->min_rows) {
            // Below minimum (only when non-empty — empty is its own, more severe, kind).
            $violations->push($this->record($contract, ContractViolation::KIND_BELOW_MIN_ROWS,
                "Row count {$rowCount} below minimum {$contract->min_rows}."));
        }

        // Stale beyond the freshness SLA.
        if ($contract->freshness_sla_hours !== null && isset($snapshot['generated_at'])) {
            $generatedAt = Carbon::parse($snapshot['generated_at']);
            if ($generatedAt->diffInHours(now()) > $contract->freshness_sla_hours) {
                $violations->push($this->record($contract, ContractViolation::KIND_STALE,
                    "Feed generated {$generatedAt->diffForHumans()}, SLA is {$contract->freshness_sla_hours}h."));
            }
        }

        return $violations;
    }

    private function record(\App\Models\DataContract $contract, string $kind, string $detail): ContractViolation
    {
        return ContractViolation::create([
            'tenant_id'        => $contract->tenant_id,
            'data_contract_id' => $contract->id,
            'feed_key'         => $contract->feed_key,
            'kind'             => $kind,
            'detail'           => $detail,
            'occurred_at'      => now(),
        ]);
    }

    /**
     * Open (unresolved) violations for a tenant.
     *
     * @return Collection<int,ContractViolation>
     */
    public function open(int $tenantId): Collection
    {
        return ContractViolation::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('resolved_at')
            ->orderByDesc('occurred_at')
            ->get();
    }
}
