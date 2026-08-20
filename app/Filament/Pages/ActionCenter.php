<?php

namespace App\Filament\Pages;

use App\Jobs\BulkActionCenterJob;
use App\Models\Action;
use App\Models\Investigation;
use App\Models\User;
use App\Models\Team;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;

class ActionCenter extends Page
{
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Action Center';

    protected static ?int $navigationSort = -1;

    protected static ?string $slug = 'action-center';

    protected string $view = 'filament.pages.action-center';

    public function getTitle(): string
    {
        return 'Action Center';
    }

    // ── Filter / tab / sort state ─────────────────────────────────────────────
    // Tab / filter props are URL-bound so the dashboard "Overdue Actions" KPI
    // card can deep-link straight to the matching tab (e.g. ?tab=overdue).
    #[Url(as: 'tab')]
    public string $activeTab     = 'all';
    #[Url(as: 'q')]
    public string $search        = '';
    #[Url(as: 'type')]
    public string $typeFilter    = '';
    #[Url(as: 'assignee')]
    public string $assigneeFilter= '';
    #[Url(as: 'priority')]
    public string $priorityFilter= '';
    public string $sortField     = 'due_at';
    public string $sortDir       = 'asc';
    public int    $perPage       = 25;
    public int    $currentPage   = 1;

    // ── Detail panel ──────────────────────────────────────────────────────────
    public ?int $selectedActionId = null;

    // ── Completion modal ──────────────────────────────────────────────────────
    public bool   $showCompleteModal  = false;
    public ?int   $completingActionId = null;
    public string $completionNotes    = '';

    // Reset page on filter change
    public function updatingActiveTab(): void      { $this->currentPage = 1; }
    public function updatingSearch(): void         { $this->currentPage = 1; }
    public function updatingTypeFilter(): void     { $this->currentPage = 1; }
    public function updatingAssigneeFilter(): void { $this->currentPage = 1; }
    public function updatingPriorityFilter(): void { $this->currentPage = 1; }

    // ── Panel actions ─────────────────────────────────────────────────────────

    public function selectAction(int $id): void
    {
        $this->selectedActionId = $this->selectedActionId === $id ? null : $id;
    }

    public function closePanel(): void
    {
        $this->selectedActionId = null;
    }

    public function openCompleteModal(int $id): void
    {
        $this->completingActionId = $id;
        $this->completionNotes    = '';
        $this->showCompleteModal  = true;
    }

    public function closeCompleteModal(): void
    {
        $this->showCompleteModal  = false;
        $this->completingActionId = null;
        $this->completionNotes    = '';
    }

    public function confirmComplete(): void
    {
        if (!$this->completingActionId) return;

        $action = Action::find($this->completingActionId);
        if (!$action) return;

        // Verify tenant scope
        $tenantId = Filament::getTenant()?->id;
        if (!$tenantId) return;
        $valid = Investigation::where('id', $action->investigation_id)
            ->where('tenant_id', $tenantId)
            ->exists();
        if (!$valid) return;

        $action->update([
            'status'           => Action::STATUS_COMPLETED,
            'completion_notes' => $this->completionNotes,
            'completed_at'     => now(),
        ]);

        $this->closeCompleteModal();

        if ($this->selectedActionId === $this->completingActionId) {
            $this->selectedActionId = null;
        }
    }

    public function markInProgress(int $id): void
    {
        $action = $this->getVerifiedAction($id);
        if (!$action || !$action->isActive()) return;
        $action->update(['status' => Action::STATUS_IN_PROGRESS]);
    }

    public function markAcknowledged(int $id): void
    {
        $action = $this->getVerifiedAction($id);
        if (!$action) return;
        $action->update([
            'status'          => Action::STATUS_ACKNOWLEDGED,
            'acknowledged_at' => now(),
        ]);
    }

    public function cancelAction(int $id): void
    {
        $action = $this->getVerifiedAction($id);
        if (!$action || !$action->isActive()) return;
        $action->update([
            'status'       => Action::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);
    }

    // ── Feature 7 — bulk selection ──────────────────────────────────────────────
    /** @var array<int,int> */
    public array $selectedActions = [];
    public bool $selectAllMatchingActions = false;
    public ?int $bulkAssigneeId = null;
    public ?int $bulkTeamIdAc = null;
    public string $bulkPriorityAc = '';

    const AC_BULK_INLINE_THRESHOLD = 150;

    public function toggleSelectedAction(int $id): void
    {
        if (in_array($id, $this->selectedActions, true)) {
            $this->selectedActions = array_values(array_diff($this->selectedActions, [$id]));
            $this->selectAllMatchingActions = false;
        } else {
            $this->selectedActions[] = $id;
        }
    }

    public function clearActionSelection(): void
    {
        $this->selectedActions = [];
        $this->selectAllMatchingActions = false;
    }

    public function toggleSelectAllMatchingActions(): void
    {
        $this->selectAllMatchingActions = ! $this->selectAllMatchingActions;
        if (! $this->selectAllMatchingActions) {
            $this->selectedActions = [];
        }
    }

    public function getMatchingActionCount(): int
    {
        return $this->baseQuery()->count();
    }

    public function getActionSelectionCount(): int
    {
        return $this->selectAllMatchingActions ? $this->getMatchingActionCount() : count($this->selectedActions);
    }

    /** @return array<int> */
    protected function targetActionIds(): array
    {
        if ($this->selectAllMatchingActions) {
            return $this->baseQuery()->pluck('actions.id')->map(fn ($i) => (int) $i)->all();
        }
        return array_map('intval', $this->selectedActions);
    }

    protected function runActionBulk(string $action, array $params = []): void
    {
        $tenantId = Filament::getTenant()?->id;
        $user     = auth()->user();
        if (! $tenantId || ! $user) {
            return;
        }

        $ids = $this->targetActionIds();
        if (empty($ids)) {
            Notification::make()->title('Nothing selected')->warning()->send();
            return;
        }

        if (count($ids) > self::AC_BULK_INLINE_THRESHOLD) {
            BulkActionCenterJob::dispatch($tenantId, $user->id, $action, $ids, $params);
            Notification::make()->title('Bulk action queued')->body(count($ids) . ' actions queued.')->success()->send();
        } else {
            $result = BulkActionCenterJob::apply($tenantId, $user->id, $action, $ids, $params);
            $msg = "{$result['succeeded']} updated" . ($result['failed'] ? ", {$result['failed']} failed" : '');
            Notification::make()->title('Bulk action complete')->body($msg)->color($result['failed'] ? 'warning' : 'success')->send();
        }

        $this->clearActionSelection();
    }

    public function bulkAssignActions(): void
    {
        if (! $this->bulkAssigneeId) {
            Notification::make()->title('Choose an assignee first')->warning()->send();
            return;
        }
        $this->runActionBulk('assign', ['assigned_to' => (int) $this->bulkAssigneeId]);
        $this->bulkAssigneeId = null;
    }

    public function bulkReassignActionTeam(): void
    {
        if (! $this->bulkTeamIdAc) {
            Notification::make()->title('Choose a team first')->warning()->send();
            return;
        }
        $this->runActionBulk('reassign_team', ['team_id' => (int) $this->bulkTeamIdAc]);
        $this->bulkTeamIdAc = null;
    }

    public function bulkChangeActionPriority(): void
    {
        if (! $this->bulkPriorityAc) {
            Notification::make()->title('Choose a priority first')->warning()->send();
            return;
        }
        $this->runActionBulk('change_priority', ['priority' => $this->bulkPriorityAc]);
        $this->bulkPriorityAc = '';
    }

    public function bulkEscalateActions(): void
    {
        $this->runActionBulk('escalate');
    }

    public function getAvailableTeams(): array
    {
        $tenantId = Filament::getTenant()?->id;
        if (! $tenantId) return [];
        return Team::where('tenant_id', $tenantId)->orderBy('name')->pluck('name', 'id')->toArray();
    }

    // ── Data helpers ──────────────────────────────────────────────────────────

    private function getVerifiedAction(int $id): ?Action
    {
        $tenantId = Filament::getTenant()?->id;
        if (!$tenantId) return null;

        $action = Action::find($id);
        if (!$action) return null;

        $valid = Investigation::where('id', $action->investigation_id)
            ->where('tenant_id', $tenantId)
            ->exists();

        return $valid ? $action : null;
    }

    private function baseQuery()
    {
        $tenantId = Filament::getTenant()?->id;

        $query = Action::whereHas('investigation', fn ($q) => $q->where('tenant_id', $tenantId))
            ->with(['investigation.primaryStore', 'assignedTo', 'assignedTeam']);

        // Tab filter
        match ($this->activeTab) {
            'overdue'    => $query->whereNotIn('status', [Action::STATUS_COMPLETED, Action::STATUS_CANCELLED])
                                  ->where('due_at', '<', now()),
            'due_today'  => $query->whereNotIn('status', [Action::STATUS_COMPLETED, Action::STATUS_CANCELLED])
                                  ->whereBetween('due_at', [now()->startOfDay(), now()->endOfDay()]),
            'due_week'   => $query->whereNotIn('status', [Action::STATUS_COMPLETED, Action::STATUS_CANCELLED])
                                  ->whereBetween('due_at', [now()->startOfDay(), now()->endOfWeek()]),
            'in_progress'=> $query->where('status', Action::STATUS_IN_PROGRESS),
            'completed'  => $query->where('status', Action::STATUS_COMPLETED),
            default      => null, // all
        };

        if ($this->search) {
            $term = $this->search;
            $query->where(fn ($q) => $q
                ->where('title', 'ilike', "%{$term}%")
                ->orWhere('description', 'ilike', "%{$term}%")
                ->orWhereHas('investigation', fn ($q2) => $q2->where('title', 'ilike', "%{$term}%"))
            );
        }

        if ($this->typeFilter) {
            $query->where('action_type', $this->typeFilter);
        }

        if ($this->assigneeFilter) {
            $query->where('assigned_to', $this->assigneeFilter);
        }

        if ($this->priorityFilter) {
            $query->where('priority', $this->priorityFilter);
        }

        return $query;
    }

    public function getPaginatedActions(): LengthAwarePaginator
    {
        $query = $this->baseQuery();

        $allowed = ['due_at', 'priority', 'status', 'created_at'];
        $field   = in_array($this->sortField, $allowed) ? $this->sortField : 'due_at';
        $dir     = $this->sortDir === 'desc' ? 'desc' : 'asc';

        if ($field === 'priority') {
            $query->orderByRaw("CASE priority WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END");
        } else {
            $query->orderBy($field, $dir);
        }

        return $query->paginate($this->perPage, ['*'], 'ac_page', $this->currentPage);
    }

    public function getSelectedAction(): ?Action
    {
        if (!$this->selectedActionId) return null;
        return Action::with([
            'investigation.primaryStore',
            'investigation.anomalies',
            'assignedTo',
            'assignedTeam',
            'createdBy',
            'anomaly',
        ])->find($this->selectedActionId);
    }

    // ── KPI counts ────────────────────────────────────────────────────────────

    public function getKpis(): array
    {
        $tenantId = Filament::getTenant()?->id;
        if (!$tenantId) return ['total' => 0, 'overdue' => 0, 'due_today' => 0, 'due_week' => 0, 'completed_mtd' => 0];

        $base = Action::whereHas('investigation', fn ($q) => $q->where('tenant_id', $tenantId));

        return [
            'total' => (clone $base)->whereNotIn('status', [Action::STATUS_COMPLETED, Action::STATUS_CANCELLED])->count(),
            'overdue' => (clone $base)->whereNotIn('status', [Action::STATUS_COMPLETED, Action::STATUS_CANCELLED])
                ->where('due_at', '<', now())->count(),
            'due_today' => (clone $base)->whereNotIn('status', [Action::STATUS_COMPLETED, Action::STATUS_CANCELLED])
                ->whereBetween('due_at', [now()->startOfDay(), now()->endOfDay()])->count(),
            'due_week' => (clone $base)->whereNotIn('status', [Action::STATUS_COMPLETED, Action::STATUS_CANCELLED])
                ->whereBetween('due_at', [now()->startOfDay(), now()->endOfWeek()])->count(),
            'completed_mtd' => (clone $base)->where('status', Action::STATUS_COMPLETED)
                ->whereMonth('completed_at', now()->month)
                ->whereYear('completed_at', now()->year)->count(),
        ];
    }

    public function getTabCounts(): array
    {
        $tenantId = Filament::getTenant()?->id;
        if (!$tenantId) return [];

        $base = Action::whereHas('investigation', fn ($q) => $q->where('tenant_id', $tenantId));
        $active = (clone $base)->whereNotIn('status', [Action::STATUS_COMPLETED, Action::STATUS_CANCELLED]);

        return [
            'all'         => (clone $base)->whereNotIn('status', [Action::STATUS_COMPLETED, Action::STATUS_CANCELLED])->count(),
            'overdue'     => (clone $active)->where('due_at', '<', now())->count(),
            'due_today'   => (clone $active)->whereBetween('due_at', [now()->startOfDay(), now()->endOfDay()])->count(),
            'due_week'    => (clone $active)->whereBetween('due_at', [now()->startOfDay(), now()->endOfWeek()])->count(),
            'in_progress' => (clone $base)->where('status', Action::STATUS_IN_PROGRESS)->count(),
            'completed'   => (clone $base)->where('status', Action::STATUS_COMPLETED)->count(),
        ];
    }

    // ── Sidebar chart data ────────────────────────────────────────────────────

    public function getSlaComplianceData(): array
    {
        $tenantId = Filament::getTenant()?->id;
        if (!$tenantId) return ['on_track' => 0, 'at_risk' => 0, 'overdue' => 0];

        $active = Action::whereHas('investigation', fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereNotIn('status', [Action::STATUS_COMPLETED, Action::STATUS_CANCELLED])
            ->whereNotNull('due_at')
            ->get(['due_at', 'status']);

        $onTrack = 0; $atRisk = 0; $overdue = 0;
        foreach ($active as $a) {
            $hrs = now()->diffInHours($a->due_at, false);
            if ($hrs < 0)   $overdue++;
            elseif ($hrs < 24) $atRisk++;
            else            $onTrack++;
        }
        return ['on_track' => $onTrack, 'at_risk' => $atRisk, 'overdue' => $overdue];
    }

    public function getActionsByType(): array
    {
        $tenantId = Filament::getTenant()?->id;
        if (!$tenantId) return [];

        return Action::whereHas('investigation', fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereNotIn('status', [Action::STATUS_COMPLETED, Action::STATUS_CANCELLED])
            ->selectRaw('action_type, count(*) as cnt')
            ->groupBy('action_type')
            ->orderByDesc('cnt')
            ->limit(8)
            ->get()
            ->map(fn ($r) => [
                'type'  => $r->action_type,
                'label' => Action::TYPE_LABELS[$r->action_type] ?? ucwords(str_replace('_', ' ', $r->action_type)),
                'count' => (int) $r->cnt,
            ])
            ->toArray();
    }

    public function getUpcomingDeadlines(): array
    {
        $tenantId = Filament::getTenant()?->id;
        if (!$tenantId) return [];

        return Action::whereHas('investigation', fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereNotIn('status', [Action::STATUS_COMPLETED, Action::STATUS_CANCELLED])
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now()->addDays(3))
            ->orderBy('due_at')
            ->limit(8)
            ->with(['assignedTo'])
            ->get()
            ->toArray();
    }

    public function getAvailableAssignees(): array
    {
        $tenantId = Filament::getTenant()?->id;
        if (!$tenantId) return [];

        return User::where('tenant_id', $tenantId)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $actions = $this->baseQuery()->orderBy('due_at')->get();

        return response()->streamDownload(function () use ($actions) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['ID', 'Title', 'Type', 'Investigation', 'Priority', 'Status', 'Assigned To', 'Due At', 'SLA Status']);
            foreach ($actions as $a) {
                fputcsv($out, [
                    $a->id,
                    $a->title,
                    $a->getTypeLabel(),
                    $a->investigation?->title ?? '—',
                    $a->getPriorityLabel(),
                    $a->getStatusLabel(),
                    $a->assignedTo?->name ?? ($a->assignedTeam?->name ?? 'Unassigned'),
                    $a->due_at?->toDateTimeString() ?? '—',
                    $a->getSlaStatus(),
                ]);
            }
            fclose($out);
        }, 'actions-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv']);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
