<?php

namespace App\Filament\Resources\InvestigationResource\Pages;

use App\Filament\Resources\InvestigationResource;
use App\Models\AnomalySetting;
use App\Models\Investigation;
use App\Models\Store;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Pagination\LengthAwarePaginator;

class ListInvestigations extends ListRecords
{
    protected static string $resource = InvestigationResource::class;

    protected string $view = 'filament.resources.investigation-resource.pages.list-investigations';

    public function getMaxContentWidth(): \Filament\Support\Enums\MaxWidth | string | null
    {
        return \Filament\Support\Enums\MaxWidth::Full;
    }

    // ── Panel state ────────────────────────────────────────────────────────────
    public ?int $selectedInvestigationId = null;

    // ── Filter / search / sort state ──────────────────────────────────────────
    public string $search        = '';
    public string $statusFilter  = 'open';
    public string $priorityFilter= '';
    public string $ruleFilter    = '';
    public string $storeFilter   = '';
    public string $sortField     = 'opened_at';
    public string $sortDir       = 'desc';
    public int    $perPage       = 25;
    public int    $currentPage   = 1;

    // Reset to page 1 when any filter changes
    public function updatingSearch(): void        { $this->currentPage = 1; }
    public function updatingStatusFilter(): void  { $this->currentPage = 1; }
    public function updatingPriorityFilter(): void{ $this->currentPage = 1; }
    public function updatingRuleFilter(): void    { $this->currentPage = 1; }
    public function updatingStoreFilter(): void   { $this->currentPage = 1; }

    // ── Panel actions ──────────────────────────────────────────────────────────
    public function selectInvestigation(int $id): void
    {
        $this->selectedInvestigationId = $this->selectedInvestigationId === $id ? null : $id;
    }

    public function closePanel(): void
    {
        $this->selectedInvestigationId = null;
    }

    // ── Data methods ───────────────────────────────────────────────────────────
    public function getInvestigations(): LengthAwarePaginator
    {
        $tenantId = Filament::getTenant()?->id;

        $query = Investigation::where('tenant_id', $tenantId)
            ->with(['assignedTeam', 'assignedUser', 'anomalies', 'primaryStore']);

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

        if ($this->priorityFilter) {
            $query->where('priority', $this->priorityFilter);
        }

        if ($this->ruleFilter) {
            $query->whereHas('anomalies', fn ($q) => $q->where('rule_type', $this->ruleFilter));
        }

        if ($this->storeFilter) {
            $query->where('primary_store_id', $this->storeFilter);
        }

        $allowedSorts = ['opened_at', 'priority', 'revenue_at_risk', 'status'];
        $sortField = in_array($this->sortField, $allowedSorts) ? $this->sortField : 'opened_at';
        $sortDir   = $this->sortDir === 'asc' ? 'asc' : 'desc';

        // Priority needs a CASE sort
        if ($sortField === 'priority') {
            $query->orderByRaw("CASE priority WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END");
        } else {
            $query->orderBy($sortField, $sortDir);
        }

        return $query->paginate($this->perPage, ['*'], 'inv_page', $this->currentPage);
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

    protected function getHeaderActions(): array
    {
        return [];
    }
}
