<?php

namespace App\Services\Assortment;

use App\Models\AssortmentGap;
use App\Models\AssortmentProgramme;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * v2.3 — tail clean-up programmes. Many store-level delists of the same
 * product are one chain-wide decision: "these lines sell below the cut in most
 * of the stores that carry them — remove them everywhere the engine proposes it".
 *
 *   candidate   a product with an open delist at ≥ MIN_STORES stores and at
 *               ≥ MIN_SHARE of the stores carrying it
 *   value       the sum of those delists (each already net of where its buyers
 *               go), as a range; the shelf metres they free where shelf files exist
 *   accept      accepts every open delist of the programme's products in one go
 *               (one owner, one due date); each is then done, and measured, per
 *               store as any decision — the programme follows them
 *
 * Only the engine's own delists are used: a store where the product sells well
 * is never asked to remove it.
 */
class TailProgrammes
{
    public const MIN_STORES = 3;

    public const MIN_SHARE = 0.5;

    public function __construct(private DecisionService $decisions) {}

    /** @return array<string, array<string,mixed>> sku => candidate, best first */
    public function candidates(Tenant $tenant): array
    {
        $tid = (int) $tenant->id;
        $busy = AssortmentProgramme::where('tenant_id', $tid)->whereIn('status', [AssortmentProgramme::PROPOSED, AssortmentProgramme::ACCEPTED])
            ->pluck('skus')->flatten()->map(fn ($s) => (string) $s)->flip();
        $rows = DB::table('assortment_gaps as g')->leftJoin('products as p', fn ($j) => $j->on('p.tenant_id', '=', 'g.tenant_id')->on('p.sku', '=', 'g.sku'))
            ->where('g.tenant_id', $tid)->where('g.type', AssortmentGap::TYPE_DELIST)->where('g.status', AssortmentGap::STATUS_OPEN)
            ->groupBy('g.sku', 'p.name', 'p.category', 'p.brand')
            ->selectRaw('g.sku, p.name, TRIM(p.category) AS category, p.brand, COUNT(*) AS n, SUM(g.value_low) AS lo, SUM(g.value_high) AS hi, SUM(g.value_mid) AS mid')
            ->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $carrying = DB::table('assortment_store_ranges')->where('tenant_id', $tid)->where('carried', true)
            ->whereIn('sku', $rows->pluck('sku')->all())->groupBy('sku')->selectRaw('sku, COUNT(*) AS n')->pluck('n', 'sku');
        $metres = $this->metres($tid, $rows->pluck('sku')->all());

        $out = [];
        foreach ($rows as $r) {
            $sku = (string) $r->sku;
            $carry = max((int) ($carrying[$sku] ?? 0), (int) $r->n);
            $share = $carry > 0 ? (int) $r->n / $carry : 0.0;
            if ((int) $r->n < self::MIN_STORES || $share < self::MIN_SHARE || isset($busy[$sku])) {
                continue;
            }
            $out[$sku] = [
                'sku' => $sku, 'name' => (string) ($r->name ?: $sku), 'category' => $r->category, 'brand' => $r->brand,
                'stores' => (int) $r->n, 'carrying' => $carry, 'share' => round($share, 3),
                'low' => round((float) $r->lo, 2), 'high' => round((float) $r->hi, 2), 'mid' => round((float) $r->mid, 2),
                'metres' => $metres[$sku] ?? null,
            ];
        }
        uasort($out, fn ($a, $b) => [$b['mid'], $a['sku']] <=> [$a['mid'], $b['sku']]);

        return $out;
    }

    /** Shelf metres the open delists of each product free (width × facings at each proposing store), where known. */
    private function metres(int $tid, array $skus): array
    {
        if (! ShelfSpace::any($tid) || $skus === []) {
            return [];
        }
        $out = $seen = [];
        foreach (DB::table('assortment_gaps as g')->join('assortment_product_space as ps', function ($j) {
            $j->on('ps.tenant_id', '=', 'g.tenant_id')->on('ps.sku', '=', 'g.sku')->where(fn ($w) => $w->whereColumn('ps.store_key', 'g.store_id')->orWhere('ps.store_key', 0));
        })->where('g.tenant_id', $tid)->where('g.type', AssortmentGap::TYPE_DELIST)->where('g.status', AssortmentGap::STATUS_OPEN)->whereIn('g.sku', $skus)
            ->whereNotNull('ps.width_cm')->get(['g.sku', 'g.store_id', 'ps.store_key', 'ps.width_cm', 'ps.facings']) as $r) {
            $k = $r->sku . '|' . $r->store_id;
            // the store's own row wins over the chain's
            if (! isset($seen[$k]) || (int) $r->store_key !== 0) {
                $seen[$k] = (float) $r->width_cm / 100 * max(1, (int) ($r->facings ?? 1));
            }
        }
        foreach ($seen as $k => $m) {
            [$sku] = explode('|', $k);
            $out[$sku] = round(($out[$sku] ?? 0) + $m, 2);
        }

        return $out;
    }

    /** @param array<int,string> $skus */
    public function create(Tenant $tenant, User $by, array $skus, ?string $name = null): AssortmentProgramme
    {
        $cands = $this->candidates($tenant);
        $skus = array_values(array_filter(array_unique(array_map('strval', $skus)), fn ($s) => isset($cands[$s])));
        abort_if($skus === [], 422, 'None of those products is a clean-up candidate any more.');
        $pick = array_intersect_key($cands, array_flip($skus));
        $metres = array_sum(array_map(fn ($c) => (float) ($c['metres'] ?? 0), $pick));

        $p = AssortmentProgramme::create([
            'tenant_id' => $tenant->id, 'status' => AssortmentProgramme::PROPOSED, 'skus' => $skus,
            'name' => $name ?: 'Tail clean-up: ' . count($skus) . ' product(s), ' . now()->format('M Y'),
            'value' => ['low' => round(array_sum(array_column($pick, 'low')), 2), 'high' => round(array_sum(array_column($pick, 'high')), 2)],
            'metres' => $metres > 0 ? round($metres, 2) : null,
            'stores' => DB::table('assortment_gaps')->where('tenant_id', $tenant->id)->where('type', AssortmentGap::TYPE_DELIST)
                ->where('status', AssortmentGap::STATUS_OPEN)->whereIn('sku', $skus)->distinct()->count('store_id'),
            'created_by' => $by->id,
        ]);
        $this->audit($p, $by, 'assortment.programme_proposed', 'Proposed ' . $p->name);

        return $p;
    }

    /** Accept every open delist of the programme's products: one owner, one due date. */
    public function accept(AssortmentProgramme $p, User $by, ?int $assigneeId = null, ?string $dueAt = null): int
    {
        abort_unless($p->status === AssortmentProgramme::PROPOSED, 422, 'Only a proposed programme can be accepted.');
        $ids = [];
        DB::transaction(function () use ($p, $by, $assigneeId, $dueAt, &$ids) {
            foreach (AssortmentGap::where('tenant_id', $p->tenant_id)->where('type', AssortmentGap::TYPE_DELIST)
                ->where('status', AssortmentGap::STATUS_OPEN)->whereIn('sku', $p->skus)->orderBy('id')->get() as $gap) {
                $this->decisions->accept($gap, $by, $assigneeId, $dueAt, 'Clean-up programme #' . $p->id, notify: false);
                $ids[] = (int) $gap->id;
            }
            abort_if($ids === [], 422, 'The delists behind this programme are no longer open — make a new one from the current candidates.');
            $p->forceFill(['status' => AssortmentProgramme::ACCEPTED, 'gap_ids' => $ids, 'decided_by' => $by->id, 'decided_at' => now(),
                'assignee_id' => $assigneeId, 'due_at' => $dueAt ? \Illuminate\Support\Carbon::parse($dueAt)->endOfDay() : null])->save();
        });
        $this->audit($p, $by, 'assortment.programme_accepted', 'Accepted ' . $p->name . ' (' . count($ids) . ' delists)');

        return count($ids);
    }

    public function cancel(AssortmentProgramme $p, User $by): void
    {
        abort_unless($p->status === AssortmentProgramme::PROPOSED, 422, 'Only a proposed programme can be cancelled; an accepted one is followed per store.');
        $p->forceFill(['status' => AssortmentProgramme::CANCELLED, 'decided_by' => $by->id, 'decided_at' => now()])->save();
        $this->audit($p, $by, 'assortment.programme_cancelled', 'Cancelled ' . $p->name);
    }

    /**
     * Store by store: of the delists it accepted, how many are done, cancelled
     * and measured, and what was measured. Marks the programme done once every
     * one is done or cancelled.
     *
     * @return array{total:int, done:int, to_do:int, cancelled:int, measured:int, worked:int, uplift:float}
     */
    public function progress(AssortmentProgramme $p): array
    {
        $gaps = AssortmentGap::where('tenant_id', $p->tenant_id)->whereIn('id', (array) ($p->gap_ids ?? []))->get(['task_status', 'measured_at', 'measurement']);
        $out = [
            'total' => $gaps->count(),
            'done' => $gaps->where('task_status', AssortmentGap::TASK_DONE)->count(),
            'to_do' => $gaps->where('task_status', AssortmentGap::TASK_TO_DO)->count(),
            'cancelled' => $gaps->where('task_status', AssortmentGap::TASK_CANCELLED)->count(),
            'measured' => $gaps->whereNotNull('measured_at')->count(),
            'worked' => $gaps->filter(fn ($g) => ($g->measurement['verdict'] ?? null) === 'success')->count(),
            'uplift' => (float) $gaps->sum(fn ($g) => (float) ($g->measurement['uplift_per_year'] ?? 0)),
        ];
        if ($p->status === AssortmentProgramme::ACCEPTED && $out['total'] > 0 && $out['to_do'] === 0) {
            $p->forceFill(['status' => AssortmentProgramme::DONE])->save();
        }

        return $out;
    }

    private function audit(AssortmentProgramme $p, User $by, string $event, string $summary): void
    {
        try {
            AuditLog::create(['tenant_id' => $p->tenant_id, 'user_id' => $by->id, 'event_type' => $event,
                'description' => mb_substr($summary, 0, 250), 'new_value' => ['programme_id' => $p->id, 'status' => $p->status], 'created_at' => now()]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
