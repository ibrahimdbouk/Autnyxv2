<?php

namespace App\Filament\Pages;

use App\Services\DataQuality\DataReadinessService;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * Slice 6 — the "can Autnyx safely use today's data?" dashboard. Per-dataset
 * READY/BLOCKED from each dataset's latest batch decision, the counts and reason
 * behind it, and exactly which detection capabilities are affected. Read-only.
 * See claude/data-quality-firewall.md.
 */
class DataReadiness extends Page
{
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-shield-check';

    protected static \UnitEnum|string|null $navigationGroup = 'Data Quality';

    protected static ?string $navigationLabel = 'Data Readiness';

    protected static ?int $navigationSort = 0;

    protected static ?string $slug = 'data-readiness';

    protected string $view = 'filament.pages.data-readiness';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user && ($user->is_super_admin || $user->is_tenant_admin));
    }

    /** @return array{overall:string, datasets:array<int,array<string,mixed>>} */
    public function getReadiness(): array
    {
        $tenantId = Filament::getTenant()?->id;
        if (! $tenantId) {
            return ['overall' => 'green', 'datasets' => []];
        }

        $rows = DB::select(
            "SELECT DISTINCT ON (data_type) data_type, state, decision, rows_promoted, rows_quarantined,
                    reason_counts, created_at
             FROM import_quality WHERE tenant_id = ? AND state IS DISTINCT FROM 'duplicate'
             ORDER BY data_type, created_at DESC",
            [$tenantId],
        );

        $datasets = [];
        $anyRed = false;
        $anyAmber = false;

        foreach ($rows as $r) {
            $rules = array_keys(array_filter(
                DataReadinessService::RULE_DATASET,
                fn ($ds) => $ds === $r->data_type,
            ));

            $r->state === 'red' ? $anyRed = true : ($r->state === 'amber' ? $anyAmber = true : null);

            $datasets[] = [
                'data_type'   => $r->data_type,
                'state'       => $r->state,
                'decision'    => $r->decision,
                'promoted'    => (int) $r->rows_promoted,
                'quarantined' => (int) $r->rows_quarantined,
                'top_reason'  => $this->topReason($r->reason_counts),
                'rules'       => $rules,
                'ready'       => $r->state !== 'red',
            ];
        }

        usort($datasets, fn ($a, $b) => $a['ready'] <=> $b['ready']); // blocked first

        return [
            'overall'  => $anyRed ? 'red' : ($anyAmber ? 'amber' : 'green'),
            'datasets' => $datasets,
        ];
    }

    private function topReason($json): ?string
    {
        $counts = is_string($json) ? (json_decode($json, true) ?: []) : (is_array($json) ? $json : []);
        if ($counts === []) {
            return null;
        }
        arsort($counts);
        $key = array_key_first($counts);

        return \App\Services\DataQuality\Reasons::label((string) $key) . " ({$counts[$key]})";
    }
}
