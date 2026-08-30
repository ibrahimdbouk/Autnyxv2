<?php

namespace App\Services\Detection;

use App\Models\Anomaly;
use App\Models\DetectionDirtyKey;

/**
 * The subject scope for one incremental detection run: the set of SKUs the run
 * should look at. Passing an instance to AnomalyDetectionService::runForTenant()
 * restricts the primed maps and the scoped rule families to these SKUs; passing
 * null runs the full scan (unchanged behaviour).
 *
 * The set is the UNION of:
 *   A — SKUs marked dirty since the last run (the detection_dirty_keys queue), and
 *   B — SKUs of every still-open anomaly (open / persisting / clearing), so the
 *       recovery lifecycle keeps advancing even where nothing new arrived.
 *
 * Scoping by SKU (rather than store-SKU pair) is deliberate: it keeps per-SKU
 * rules (sales), per-(store,SKU) rules (stockout, negative…) and cross-store
 * rules (multi-location) all correct, because every store row for a changed SKU
 * is loaded. buildCoverageSets() then derives the reconciler's evaluability from
 * the (now scoped) primed maps, so recovery stays correct for free.
 *
 * See claude/incremental-detection-design.md.
 */
class RunScope
{
    /** @param array<int,string> $skus */
    private function __construct(
        private array $skus,
        private int $maxDirtyId,
    ) {
    }

    /**
     * Build the scope for a tenant. Returns null when the union exceeds
     * $maxSkus — the caller should fall back to a full run for that tenant.
     */
    public static function forTenant(int $tenantId, int $maxSkus): ?self
    {
        $dirty      = DetectionDirtyKey::where('tenant_id', $tenantId)->get(['id', 'sku']);
        $maxDirtyId = (int) ($dirty->max('id') ?? 0);

        $openSkus = Anomaly::where('tenant_id', $tenantId)
            ->whereIn('lifecycle_state', [
                Anomaly::LIFECYCLE_OPEN,
                Anomaly::LIFECYCLE_PERSISTING,
                Anomaly::LIFECYCLE_CLEARING,
            ])
            ->pluck('sku');

        $skus = $dirty->pluck('sku')
            ->merge($openSkus)
            ->filter(fn ($s) => $s !== null && $s !== '')
            ->map(fn ($s) => trim((string) $s))
            ->unique()
            ->values()
            ->all();

        if (count($skus) > $maxSkus) {
            return null; // too broad — a full run is cheaper and safer
        }

        return new self($skus, $maxDirtyId);
    }

    public function isEmpty(): bool
    {
        return $this->skus === [];
    }

    /** @return array<int,string> */
    public function skus(): array
    {
        return $this->skus;
    }

    /** Highest dirty-key id folded into this scope — safe to delete up to here after a successful run. */
    public function maxDirtyId(): int
    {
        return $this->maxDirtyId;
    }

    /**
     * Constrain a query to the scope's SKU set. An empty scope matches nothing.
     *
     * @param  \Illuminate\Contracts\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $query
     */
    public function constrain($query, string $skuColumn = 'sku')
    {
        if ($this->skus === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($skuColumn, $this->skus);
    }

    /**
     * A guarded SQL fragment (plus its bindings) for raw DB::select() rules —
     * appended inside a WHERE as "AND <col> IN (?, ?, …)". Empty scope → "AND 1 = 0".
     * Callers merge the returned bindings positionally where the fragment sits.
     *
     * @return array{0:string,1:array<int,string>}
     */
    public function sqlClause(string $skuColumn = 'sku'): array
    {
        if ($this->skus === []) {
            return [' AND 1 = 0', []];
        }

        $placeholders = implode(', ', array_fill(0, count($this->skus), '?'));

        return [" AND {$skuColumn} IN ({$placeholders})", $this->skus];
    }
}
