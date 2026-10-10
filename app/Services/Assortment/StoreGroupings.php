<?php

namespace App\Services\Assortment;

use App\Models\AssortmentGrouping;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Intelligence\Clustering\Strategies\RangeMixClustering;
use Illuminate\Support\Facades\DB;

/**
 * v2.4 — demand-based store groups for Assortment, versioned and approved.
 *
 *   propose   the platform's range-mix clustering (what stores sell), kept stable
 *             against the groups in force; compared with them: how many stores'
 *             peers change, group sizes before and after, stores left unjudged
 *   approve   an admin approves: it is the Assortment peer groups from the next run
 *             (the platform's own clusters, used by Root Cause, are not touched);
 *             the previous approved version is replaced
 *   retire    back to the platform clusters or store formats — one action
 *
 * Every decision, benchmark and plan made under it carries the version in its peer
 * group key (range:v<version>:<n>), so a result can always be traced to the groups
 * it was judged in.
 */
class StoreGroupings
{
    /** A store's peers count as changed when fewer than half of them are the same. */
    public const MOVED_BELOW = 0.5;

    public static function active(int $tenantId): ?AssortmentGrouping
    {
        return AssortmentGrouping::where('tenant_id', $tenantId)->where('status', AssortmentGrouping::APPROVED)->latest('version')->first();
    }

    public static function members(int $tenantId, string $key): array
    {
        if (! preg_match('/^range:v(\d+):/', $key, $m)) {
            return [];
        }
        $g = AssortmentGrouping::where('tenant_id', $tenantId)->where('version', (int) $m[1])->first();

        return array_map('intval', (array) ($g?->groups[$key]['members'] ?? []));
    }

    public function propose(Tenant $tenant, ?User $by = null): AssortmentGrouping
    {
        $tid = (int) $tenant->id;
        $current = app(PeerGroups::class)->build($tid);
        $clusters = app(RangeMixClustering::class)->clusterWith($tid, $current['assignment']);
        abort_if($clusters === [], 422, 'No store has sales in the last 90 days to group.');
        $version = (int) AssortmentGrouping::where('tenant_id', $tid)->max('version') + 1;

        $groups = $assignment = [];
        foreach ($clusters as $i => $c) {
            $key = 'range:v' . $version . ':' . ($i + 1);
            $groups[$key] = ['key' => $key, 'label' => $c['label'], 'members' => $c['store_ids'], 'profile' => $c['params']];
            foreach ($c['store_ids'] as $id) {
                $assignment[(int) $id] = $key;
            }
        }

        AssortmentGrouping::where('tenant_id', $tid)->where('status', AssortmentGrouping::PROPOSED)
            ->update(['status' => AssortmentGrouping::SUPERSEDED, 'decision_note' => 'A newer proposal replaced it']);

        $g = AssortmentGrouping::create([
            'tenant_id' => $tid, 'version' => $version, 'status' => AssortmentGrouping::PROPOSED, 'method' => 'range',
            'groups' => $groups, 'assignment' => $assignment, 'comparison' => $this->compare($tid, $current, $groups, $assignment),
        ]);
        if ($by) {
            $this->audit($g, $by, 'assortment.grouping_proposed', 'Store groups ' . $g->label() . ' proposed');
        }

        return $g;
    }

    /** Against the peer groups in force: per store, its peers now and proposed, and whether they change. */
    public function compare(int $tid, array $current, array $groups, array $assignment): array
    {
        $min = max(2, (int) config('assortment.min_peer_group', 5));
        $names = DB::table('stores')->where('tenant_id', $tid)->get(['id', 'name', 'format'])->keyBy('id');
        // A store outside the proposed groups (no recent sales) falls back to every store of its format, as PeerGroups does.
        $byFormat = [];
        foreach ($names as $id => $s) {
            $byFormat[ShelfSpace::formatSlug($s->format)][] = (int) $id;
        }
        $stores = [];
        $moved = 0;
        foreach ($names as $id => $s) {
            $id = (int) $id;
            $nowKey = $current['assignment'][$id] ?? null;
            $newKey = $assignment[$id] ?? null;
            $fallback = $newKey === null ? ($byFormat[ShelfSpace::formatSlug($s->format)] ?? []) : [];
            $fallsBack = $newKey === null && count($fallback) >= $min;
            $nowPeers = array_values(array_diff($nowKey ? $current['groups'][$nowKey]['members'] : [], [$id]));
            $newPeers = array_values(array_diff($newKey ? $groups[$newKey]['members'] : $fallback, [$id]));
            $union = count(array_unique(array_merge($nowPeers, $newPeers)));
            $same = $union > 0 ? count(array_intersect($nowPeers, $newPeers)) / $union : 1.0;
            $changes = ($newKey !== null || $fallsBack) && ($nowKey === null || $same < self::MOVED_BELOW);
            $moved += $changes ? 1 : 0;
            $stores[$id] = [
                'store_id' => $id, 'name' => (string) $s->name, 'format' => $s->format,
                'now' => $nowKey ? $current['groups'][$nowKey]['label'] : 'Not judged (too few similar stores)',
                'proposed' => $newKey ? $groups[$newKey]['label']
                    : ($fallsBack ? 'All ' . \Illuminate\Support\Str::headline(ShelfSpace::formatSlug($s->format)) . ' stores (no recent sales to group by)' : 'Not judged (no recent sales, too few of its format)'),
                'same_peers' => round($same, 2), 'moves' => $changes,
            ];
        }
        $sizes = fn (array $gs) => array_values(array_map(fn ($g) => count($g['members']), $gs));

        return [
            'stores' => $stores, 'moved' => $moved, 'total' => count($stores),
            'groups_now' => count($current['groups']), 'groups_proposed' => count($groups),
            'sizes_now' => $sizes($current['groups']), 'sizes_proposed' => $sizes($groups),
            'unjudged_now' => count($current['unjudged']),
            'unjudged_proposed' => count(array_filter($names->all(), fn ($s, $id) => isset($assignment[(int) $id])
                ? count($groups[$assignment[(int) $id]]['members']) < $min
                : count($byFormat[ShelfSpace::formatSlug($s->format)] ?? []) < $min, ARRAY_FILTER_USE_BOTH)),
        ];
    }

    public function approve(AssortmentGrouping $g, User $by, ?string $note = null): void
    {
        abort_unless($g->status === AssortmentGrouping::PROPOSED, 422, 'Only a proposal can be approved.');
        DB::transaction(function () use ($g, $by, $note) {
            AssortmentGrouping::where('tenant_id', $g->tenant_id)->where('status', AssortmentGrouping::APPROVED)
                ->update(['status' => AssortmentGrouping::SUPERSEDED]);
            $g->forceFill(['status' => AssortmentGrouping::APPROVED, 'decided_by' => $by->id, 'decided_at' => now(),
                'decision_note' => $note ? mb_substr($note, 0, 500) : null])->save();
        });
        $this->audit($g, $by, 'assortment.grouping_approved', 'Store groups ' . $g->label() . ' approved: used from the next run');
    }

    public function reject(AssortmentGrouping $g, User $by, ?string $note = null): void
    {
        abort_unless($g->status === AssortmentGrouping::PROPOSED, 422, 'Only a proposal can be rejected.');
        $g->forceFill(['status' => AssortmentGrouping::REJECTED, 'decided_by' => $by->id, 'decided_at' => now(),
            'decision_note' => $note ? mb_substr($note, 0, 500) : null])->save();
        $this->audit($g, $by, 'assortment.grouping_rejected', 'Store groups ' . $g->label() . ' rejected');
    }

    public function retire(Tenant $tenant, User $by): void
    {
        $g = self::active((int) $tenant->id);
        abort_if($g === null, 422, 'No store groups are in force.');
        $g->forceFill(['status' => AssortmentGrouping::RETIRED])->save();
        $this->audit($g, $by, 'assortment.grouping_retired', 'Store groups ' . $g->label() . ' retired: back to clusters and formats');
    }

    private function audit(AssortmentGrouping $g, User $by, string $event, string $summary): void
    {
        try {
            AuditLog::create(['tenant_id' => $g->tenant_id, 'user_id' => $by->id, 'event_type' => $event, 'description' => mb_substr($summary, 0, 250),
                'new_value' => ['grouping_id' => $g->id, 'version' => $g->version, 'status' => $g->status], 'created_at' => now()]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
