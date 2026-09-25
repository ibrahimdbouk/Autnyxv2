<?php

namespace App\Filament\Resources\InvestigationResource\Pages;

use App\Filament\Resources\InvestigationResource;
use App\Jobs\BulkInvestigationActionJob;
use App\Models\AnomalySetting;
use App\Models\Investigation;
use App\Models\Store;
use App\Models\Team;
use App\Services\Noise\SnoozeService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;

class ListInvestigations extends ListRecords
{
    use \App\Filament\Concerns\SanitizesUrlState;   // WP7.2

    protected function urlRules(): array
    {
        return [
            'search'           => 'string',
            'statusFilter'     => ['', 'open', 'in_progress', 'resolved', 'closed'],
            'priorityFilter'   => ['', 'high_critical', 'low', 'medium', 'high', 'critical'],
            'ruleFilter'       => 'string',
            'storeFilter'      => 'int',
            'confidenceFilter' => 'string',
            'teamFilter'       => 'int',
            'openedFrom'       => 'date',
            'openedTo'         => 'date',
            'minValue'         => 'numeric',
        ];
    }

    protected static string $resource = InvestigationResource::class;

    protected string $view = 'filament.resources.investigation-resource.pages.list-investigations';

    // ── Panel state ────────────────────────────────────────────────────────────
    public ?int $selectedInvestigationId = null;

    // ── Filter / search / sort state ──────────────────────────────────────────
    // URL-bound so the dashboard KPI cards can deep-link with a pre-applied
    // filter (e.g. ?status=open&priority=high_critical).
    #[Url(as: 'q')]
    public $search        = '';
    #[Url(as: 'status')]
    public $statusFilter  = 'open';
    #[Url(as: 'priority')]
    public $priorityFilter= '';
    #[Url(as: 'rule')]
    public $ruleFilter    = '';
    #[Url(as: 'store')]
    public $storeFilter   = '';
    #[Url(as: 'conf')]
    public $confidenceFilter = '';
    #[Url(as: 'team')]
    public $teamFilter    = '';
    #[Url(as: 'from')]
    public $openedFrom    = '';
    #[Url(as: 'to')]
    public $openedTo      = '';
    #[Url(as: 'min')]
    public $minValue      = '';
    public string $sortField     = 'revenue_at_risk';
    public string $sortDir       = 'desc';
    #[\Livewire\Attributes\Locked] // WP2.5: not client-settable (a tampered 10^7 page size)
    public int    $perPage       = 25;
    public int    $currentPage   = 1;

    // Reset to page 1 when any filter changes
    public function updatingSearch(): void        { $this->currentPage = 1; }
    public function updatingStatusFilter(): void  { $this->currentPage = 1; }
    public function updatingPriorityFilter(): void{ $this->currentPage = 1; }
    public function updatingRuleFilter(): void    { $this->currentPage = 1; }
    public function updatingStoreFilter(): void   { $this->currentPage = 1; }
    public function updatingConfidenceFilter(): void { $this->currentPage = 1; }
    public function updatingTeamFilter(): void    { $this->currentPage = 1; }
    public function updatingOpenedFrom(): void    { $this->currentPage = 1; }
    public function updatingOpenedTo(): void      { $this->currentPage = 1; }
    public function updatingMinValue(): void      { $this->currentPage = 1; }

    /** Clear every filter back to the default view. */
    public function resetFilters(): void
    {
        $this->search = '';
        $this->statusFilter = 'open';
        $this->priorityFilter = '';
        $this->ruleFilter = '';
        $this->storeFilter = '';
        $this->confidenceFilter = '';
        $this->teamFilter = '';
        $this->openedFrom = '';
        $this->openedTo = '';
        $this->minValue = '';
        $this->currentPage = 1;
    }

    // ── Feature 7 — bulk selection state ────────────────────────────────────────
    /** @var array<int,int> */
    public array $selected = [];
    public bool $selectAllMatching = false;
    public ?int $bulkTeamId = null;
    public string $bulkPriority = '';

    /** Above this count, bulk operations are queued instead of run inline. */
    const BULK_INLINE_THRESHOLD = 150;

    // ── Panel actions ──────────────────────────────────────────────────────────
    public function selectInvestigation(int $id): void
    {
        $this->selectedInvestigationId = $this->selectedInvestigationId === $id ? null : $id;
    }

    // ── Feature 7 — selection helpers ───────────────────────────────────────────
    public function toggleSelected(int $id): void
    {
        if (in_array($id, $this->selected, true)) {
            $this->selected = array_values(array_diff($this->selected, [$id]));
            $this->selectAllMatching = false;
        } else {
            $this->selected[] = $id;
        }
    }

    public function clearSelection(): void
    {
        $this->selected = [];
        $this->selectAllMatching = false;
    }

    public function toggleSelectAllMatching(): void
    {
        $this->selectAllMatching = ! $this->selectAllMatching;
        if (! $this->selectAllMatching) {
            $this->selected = [];
        }
    }

    public function getMatchingCount(): int
    {
        return $this->filteredQuery()->count();
    }

    /** @return array<int> */
    protected function targetIds(): array
    {
        if ($this->selectAllMatching) {
            return $this->filteredQuery()->pluck('id')->map(fn ($i) => (int) $i)->all();
        }
        return array_map('intval', $this->selected);
    }

    public function getSelectionCount(): int
    {
        return $this->selectAllMatching ? $this->getMatchingCount() : count($this->selected);
    }

    protected function runBulk(string $action, array $params = [], bool $requiresPermission = true): void
    {
        $tenantId = Filament::getTenant()?->id;
        $user     = auth()->user();
        if (! $tenantId || ! $user) {
            return;
        }

        // Permission gate for consequential actions
        if ($requiresPermission && ! $user->canReassignInvestigation()) {
            Notification::make()->title('Not permitted')->danger()->send();
            return;
        }

        $ids = $this->targetIds();
        if (empty($ids)) {
            Notification::make()->title('Nothing selected')->warning()->send();
            return;
        }

        if (count($ids) > self::BULK_INLINE_THRESHOLD) {
            BulkInvestigationActionJob::dispatch($tenantId, $user->id, $action, $ids, $params);
            Notification::make()
                ->title('Bulk action queued')
                ->body(count($ids) . ' investigations queued — you\'ll be notified when it finishes.')
                ->success()->send();
        } else {
            $result = BulkInvestigationActionJob::apply($tenantId, $user->id, $action, $ids, $params, app(SnoozeService::class));
            $msg = "{$result['succeeded']} updated" . ($result['failed'] ? ", {$result['failed']} failed" : '');
            Notification::make()
                ->title('Bulk action complete')
                ->body($msg)
                ->color($result['failed'] ? 'warning' : 'success')
                ->send();
        }

        $this->clearSelection();
    }

    public function bulkAssignToMe(): void
    {
        // "Assign to me" is allowed for any authenticated user.
        $this->runBulk('assign_to_me', [], requiresPermission: false);
    }

    public function bulkReassignTeam(): void
    {
        if (! $this->bulkTeamId) {
            Notification::make()->title('Choose a team first')->warning()->send();
            return;
        }
        $this->runBulk('reassign_team', ['team_id' => (int) $this->bulkTeamId]);
        $this->bulkTeamId = null;
    }

    public function bulkChangePriority(): void
    {
        if (! $this->bulkPriority) {
            Notification::make()->title('Choose a priority first')->warning()->send();
            return;
        }
        $this->runBulk('change_priority', ['priority' => $this->bulkPriority]);
        $this->bulkPriority = '';
    }

    public function bulkSnooze(): void
    {
        // V1 bulk snooze: 7 days, reason "known issue". Per-investigation snooze
        // offers the full duration/reason options.
        $this->runBulk('snooze', ['duration' => '7d', 'reason' => 'known_issue'], requiresPermission: false);
    }

    public function bulkDismiss(): void
    {
        if (! (auth()->user()?->canCloseInvestigation() ?? false)) {
            Notification::make()->title('Not permitted')->danger()->send();
            return;
        }
        $this->runBulk('dismiss', [], requiresPermission: false);
    }

    public function bulkExport(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $ids = $this->targetIds();
        $tenantId = Filament::getTenant()?->id;

        // WP2.4 (audit L1): with nothing selected, export what the user is
        // looking at (the active filters) — not every investigation in the tenant.
        $rows = (empty($ids) ? $this->filteredQuery() : Investigation::query()->whereIn('id', $ids))
            ->where('tenant_id', $tenantId)
            ->with(['assignedTeam'])
            ->get();

        $filename = 'investigations_' . now()->format('Ymd_His') . '.csv';

        // WP2.4 (audit L1/L3): CSV exports are audited like PDF/XLSX ones.
        if ($__tid = \Filament\Facades\Filament::getTenant()?->id) {
            \App\Support\ExportAudit::log($__tid, 'investigations', 'csv');
        }
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            \App\Support\ExportAudit::putcsv($out, ['ID', 'Title', 'Status', 'Priority', 'SKU', 'Team', 'Revenue at Risk', 'Observed Recovery', 'Opened At']);
            foreach ($rows as $inv) {
                \App\Support\ExportAudit::putcsv($out, [
                    $inv->id,
                    $inv->title,
                    $inv->status,
                    $inv->priority,
                    $inv->primary_sku,
                    $inv->assignedTeam?->name,
                    $inv->revenue_at_risk,
                    $inv->observed_recovery,
                    optional($inv->opened_at)->toDateTimeString(),
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function closePanel(): void
    {
        $this->selectedInvestigationId = null;
    }

    // ── Data methods ───────────────────────────────────────────────────────────
    /**
     * Build the tenant-scoped, filtered query (no sort / pagination).
     * Shared by the list view and bulk "select all matching".
     */
    protected function filteredQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $tenantId = Filament::getTenant()?->id;

        $query = Investigation::where('tenant_id', $tenantId);

        if ($this->search) {
            $term = $this->search;
            $query->where(fn ($q) => $q
                ->where('title', 'ilike', "%{$term}%")
                ->orWhere('primary_sku', 'ilike', "%{$term}%")
                ->orWhere('description', 'ilike', "%{$term}%")
            );
        }

        if ($this->statusFilter === 'open') {
            $query->whereIn('status', ['open', 'in_progress']);
        } elseif ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        if ($this->priorityFilter === 'high_critical') {
            // Deep-link value used by the dashboard "High Priority" KPI card.
            $query->whereIn('priority', ['high', 'critical']);
        } elseif ($this->priorityFilter) {
            $query->where('priority', $this->priorityFilter);
        }

        if ($this->ruleFilter) {
            $query->whereHas('anomalies', fn ($q) => $q->where('rule_type', $this->ruleFilter));
        }

        if ($this->storeFilter) {
            $query->where('primary_store_id', $this->storeFilter);
        }

        if ($this->confidenceFilter) {
            $query->where('ai_confidence', $this->confidenceFilter);
        }

        if ($this->teamFilter) {
            $query->where('assigned_team_id', $this->teamFilter);
        }

        if ($this->openedFrom) {
            $query->whereDate('opened_at', '>=', $this->openedFrom);
        }
        if ($this->openedTo) {
            $query->whereDate('opened_at', '<=', $this->openedTo);
        }

        if ($this->minValue !== '' && is_numeric($this->minValue)) {
            $query->where('revenue_at_risk', '>=', (float) $this->minValue);
        }

        return $query;
    }

    public function getInvestigations(): LengthAwarePaginator
    {
        $query = $this->filteredQuery()
            ->with(['assignedTeam', 'assignedUser', 'anomalies', 'primaryStore']);

        $allowedSorts = ['opened_at', 'priority', 'revenue_at_risk', 'status'];
        $sortField = in_array($this->sortField, $allowedSorts) ? $this->sortField : 'opened_at';
        $sortDir   = $this->sortDir === 'asc' ? 'asc' : 'desc';

        // Priority needs a CASE sort
        if ($sortField === 'priority') {
            $query->orderByRaw("CASE priority WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END " . ($sortDir === 'asc' ? 'DESC' : 'ASC'));
        } elseif ($sortField === 'revenue_at_risk') {
            // WP1.4: Postgres puts NULLs first on DESC — keep un-valued rows last.
            $query->orderByRaw('revenue_at_risk ' . strtoupper($sortDir) . ' NULLS LAST');
        } else {
            $query->orderBy($sortField, $sortDir);
        }

        return $query->paginate(min(100, max(10, $this->perPage)), ['*'], 'inv_page', max(1, $this->currentPage));
    }

    public function getOpenCount(): int
    {
        $tenantId = Filament::getTenant()?->id;
        if (!$tenantId) return 0;
        return Investigation::where('tenant_id', $tenantId)
            ->whereIn('status', ['open', 'in_progress'])
            ->count();
    }

    public function getSelectedInvestigation(): ?Investigation
    {
        if (!$this->selectedInvestigationId) return null;
        return Investigation::with([
            'anomalies',
            'evidence',
            'actions.assignedTo',
            'actions.assignedTeam',
            'assignedTeam',
            'assignedUser',
            'outcome',
            'primaryStore',
            'tenant',
        ])->find($this->selectedInvestigationId);
    }

    public function getAvailableRules(): array
    {
        return collect(AnomalySetting::RULES ?? [])
            ->mapWithKeys(fn ($r, $k) => [$k => $r['label'] ?? $k])
            ->toArray();
    }

    public function getAvailableStores(): array
    {
        $tenantId = Filament::getTenant()?->id;
        if (!$tenantId) return [];
        return Store::where('tenant_id', $tenantId)->orderBy('name')->pluck('name', 'id')->toArray();
    }

    public function getAvailableTeams(): array
    {
        $tenantId = Filament::getTenant()?->id;
        if (!$tenantId) return [];
        return Team::where('tenant_id', $tenantId)->orderBy('name')->pluck('name', 'id')->toArray();
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
