<x-filament-panels::page>
@php
use App\Models\Action;
use App\Filament\Resources\InvestigationResource;

$kpis        = $this->getKpis();
$tabCounts   = $this->getTabCounts();
$slaData     = $this->getSlaComplianceData();
$byType      = $this->getActionsByType();
$deadlines   = $this->getUpcomingDeadlines();
$actions     = $this->getActions();
$assignees   = $this->getAvailableAssignees();
$selectedAct = $this->getSelectedAction();

$acSlaLabel = static function(string $s): string {
    return match($s) {
        'overdue'  => 'Overdue',
        'critical' => '< 4h',
        'warning'  => '< 24h',
        'ok'       => 'On Track',
        default    => '—',
    };
};

$acSlaClass = static function(string $s): string {
    return match($s) {
        'overdue'  => 'ac-sla-overdue',
        'critical' => 'ac-sla-critical',
        'warning'  => 'ac-sla-warning',
        'ok'       => 'ac-sla-ok',
        default    => 'ac-sla-none',
    };
};

$acPriClass = static function(?string $p): string {
    return match($p) {
        'critical' => 'ac-pri-critical',
        'high'     => 'ac-pri-high',
        'medium'   => 'ac-pri-medium',
        default    => 'ac-pri-low',
    };
};

$acStatusClass = static function(?string $s): string {
    return match($s) {
        'completed'    => 'ac-status-completed',
        'in_progress'  => 'ac-status-inprogress',
        'acknowledged' => 'ac-status-acknowledged',
        'blocked'      => 'ac-status-blocked',
        'assigned'     => 'ac-status-assigned',
        'cancelled'    => 'ac-status-cancelled',
        default        => 'ac-status-unassigned',
    };
};

$acDueLabel = static function(?string $due): string {
    if (!$due) return '—';
    try {
        $dt = \Carbon\Carbon::parse($due);
        if ($dt->isToday()) return 'Today ' . $dt->format('H:i');
        if ($dt->isTomorrow()) return 'Tomorrow ' . $dt->format('H:i');
        if ($dt->isPast()) return $dt->format('M j') . ' (overdue)';
        return $dt->format('M j, H:i');
    } catch (\Exception $e) { return '—'; }
};
@endphp

<style>
/* ── Reset / base ──────────────────────────────────────────────────────────── */
.ac-wrap { display:flex; flex-direction:column; gap:1.25rem; }

/* ── KPI row ───────────────────────────────────────────────────────────────── */
.ac-kpi-row { display:grid; grid-template-columns:repeat(5,1fr); gap:.875rem; }
.ac-kpi {
    background:#fff; border:1px solid #e5e7eb; border-radius:.5rem;
    padding:.875rem 1rem; display:flex; flex-direction:column; gap:.25rem;
    box-shadow:0 1px 2px rgba(0,0,0,.04);
}
.ac-kpi-label { font-size:.7rem; font-weight:600; text-transform:uppercase; letter-spacing:.06em; color:#9ca3af; }
.ac-kpi-value { font-size:1.75rem; font-weight:700; color:#111827; line-height:1; }
.ac-kpi-value.danger { color:#dc2626; }
.ac-kpi-value.warning { color:#f59e0b; }
.ac-kpi-value.success { color:#16a34a; }
.ac-kpi-sub { font-size:.72rem; color:#6b7280; margin-top:.125rem; }

/* ── Toolbar ───────────────────────────────────────────────────────────────── */
.ac-toolbar {
    display:flex; gap:.625rem; align-items:center; flex-wrap:wrap;
    background:#fff; border:1px solid #e5e7eb; border-radius:.5rem;
    padding:.625rem .875rem; box-shadow:0 1px 2px rgba(0,0,0,.04);
}
.ac-search-wrap { position:relative; flex:1; min-width:180px; max-width:280px; }
.ac-search-icon { position:absolute; left:.625rem; top:50%; transform:translateY(-50%); color:#9ca3af; width:.875rem; height:.875rem; pointer-events:none; }
.ac-search {
    width:100%; padding:.4rem .625rem .4rem 2rem;
    border:1px solid #d1d5db; border-radius:.375rem;
    font-size:.8rem; color:#111827; background:#f9fafb;
    outline:none; transition:border-color .15s;
}
.ac-search:focus { border-color:#7c3aed; background:#fff; }
.ac-select {
    padding:.4rem .625rem; border:1px solid #d1d5db; border-radius:.375rem;
    font-size:.8rem; color:#111827; background:#f9fafb;
    outline:none; cursor:pointer;
}
.ac-select:focus { border-color:#7c3aed; }
.ac-spacer { flex:1; }
.ac-export-btn {
    display:flex; align-items:center; gap:.375rem;
    padding:.4rem .75rem; background:#f3f4f6;
    border:1px solid #d1d5db; border-radius:.375rem;
    font-size:.775rem; font-weight:500; color:#374151;
    cursor:pointer; text-decoration:none; transition:background .15s;
}
.ac-export-btn:hover { background:#e5e7eb; }

/* ── Tabs ──────────────────────────────────────────────────────────────────── */
.ac-tabs { display:flex; gap:0; border-bottom:2px solid #e5e7eb; }
.ac-tab {
    padding:.5rem 1rem; font-size:.8rem; font-weight:500; color:#6b7280;
    cursor:pointer; border-bottom:2px solid transparent; margin-bottom:-2px;
    transition:color .15s, border-color .15s; display:flex; align-items:center; gap:.375rem;
    background:none; border-top:none; border-left:none; border-right:none;
}
.ac-tab:hover { color:#374151; }
.ac-tab.active { color:#6d28d9; border-bottom-color:#6d28d9; font-weight:600; }
.ac-tab-count {
    background:#f3f4f6; color:#6b7280;
    border-radius:9999px; font-size:.65rem; font-weight:600;
    padding:.05rem .4rem; min-width:1.25rem; text-align:center;
}
.ac-tab.active .ac-tab-count { background:#ede9fe; color:#6d28d9; }
.ac-tab-count.danger { background:#fee2e2; color:#dc2626; }

/* ── Main layout ───────────────────────────────────────────────────────────── */
.ac-main { display:flex; gap:1rem; align-items:flex-start; }
.ac-content-area { flex:1; min-width:0; display:flex; flex-direction:column; gap:.75rem; }
.ac-sidebar { width:280px; flex-shrink:0; display:flex; flex-direction:column; gap:.875rem; }

/* ── Table card ────────────────────────────────────────────────────────────── */
.ac-card {
    background:#fff; border:1px solid #e5e7eb; border-radius:.5rem;
    box-shadow:0 1px 2px rgba(0,0,0,.04); overflow:hidden;
}
.ac-table { width:100%; border-collapse:collapse; font-size:.8rem; }
.ac-table thead th {
    padding:.5rem .75rem; text-align:left; font-size:.68rem; font-weight:600;
    text-transform:uppercase; letter-spacing:.06em; color:#6b7280;
    background:#f9fafb; border-bottom:1px solid #e5e7eb; white-space:nowrap;
    cursor:pointer; user-select:none;
}
.ac-table thead th:hover { color:#374151; }
.ac-table thead th .sort-icon { margin-left:.25rem; opacity:.5; font-size:.65rem; }
.ac-table thead th.sorted { color:#6d28d9; }
.ac-table thead th.sorted .sort-icon { opacity:1; }
.ac-table tbody tr {
    border-bottom:1px solid #f3f4f6;
    transition:background .1s; cursor:pointer;
}
.ac-table tbody tr:hover { background:#fafafa; }
.ac-table tbody tr.ac-row-selected { background:#faf5ff; border-left:2px solid #7c3aed; }
.ac-table tbody tr:last-child { border-bottom:none; }
.ac-table td { padding:.6rem .75rem; color:#374151; vertical-align:middle; }

/* ── Priority dot ──────────────────────────────────────────────────────────── */
.ac-pri-dot { width:.5rem; height:.5rem; border-radius:9999px; display:inline-block; flex-shrink:0; }
.ac-pri-critical { background:#dc2626; }
.ac-pri-high     { background:#f59e0b; }
.ac-pri-medium   { background:#3b82f6; }
.ac-pri-low      { background:#d1d5db; }

/* ── Status badge ──────────────────────────────────────────────────────────── */
.ac-badge {
    display:inline-flex; align-items:center;
    padding:.15rem .5rem; border-radius:9999px;
    font-size:.68rem; font-weight:600; white-space:nowrap;
}
.ac-status-unassigned   { background:#f3f4f6; color:#6b7280; }
.ac-status-assigned     { background:#fef3c7; color:#92400e; }
.ac-status-acknowledged { background:#dbeafe; color:#1e40af; }
.ac-status-inprogress   { background:#ede9fe; color:#5b21b6; }
.ac-status-blocked      { background:#fee2e2; color:#991b1b; }
.ac-status-completed    { background:#d1fae5; color:#065f46; }
.ac-status-cancelled    { background:#f3f4f6; color:#9ca3af; }

/* ── SLA badge ─────────────────────────────────────────────────────────────── */
.ac-sla { display:inline-flex; align-items:center; gap:.25rem; font-size:.72rem; font-weight:600; white-space:nowrap; }
.ac-sla-dot { width:.4rem; height:.4rem; border-radius:9999px; }
.ac-sla-ok       .ac-sla-dot { background:#16a34a; }
.ac-sla-warning  .ac-sla-dot { background:#f59e0b; }
.ac-sla-critical .ac-sla-dot { background:#dc2626; }
.ac-sla-overdue  .ac-sla-dot { background:#dc2626; }
.ac-sla-none     .ac-sla-dot { background:#d1d5db; }
.ac-sla-ok      { color:#16a34a; }
.ac-sla-warning { color:#d97706; }
.ac-sla-critical{ color:#dc2626; }
.ac-sla-overdue { color:#dc2626; }
.ac-sla-none    { color:#9ca3af; }

/* ── Action title cell ─────────────────────────────────────────────────────── */
.ac-action-cell { display:flex; align-items:center; gap:.5rem; }
.ac-action-title { font-weight:500; color:#111827; font-size:.8rem; line-height:1.3; }
.ac-action-sub { font-size:.72rem; color:#9ca3af; }

/* ── Assignee chip ─────────────────────────────────────────────────────────── */
.ac-assignee {
    display:inline-flex; align-items:center; gap:.3rem;
    font-size:.75rem; color:#374151;
}
.ac-avatar {
    width:1.25rem; height:1.25rem; border-radius:9999px;
    background:#ede9fe; color:#6d28d9;
    font-size:.6rem; font-weight:700;
    display:inline-flex; align-items:center; justify-content:center;
    flex-shrink:0;
}
.ac-unassigned { font-size:.75rem; color:#9ca3af; font-style:italic; }

/* ── Empty state ───────────────────────────────────────────────────────────── */
.ac-empty { padding:3rem 1rem; text-align:center; color:#9ca3af; font-size:.875rem; }
.ac-empty-icon { font-size:2rem; margin-bottom:.5rem; }

/* ── Pagination ────────────────────────────────────────────────────────────── */
.ac-pagination {
    display:flex; align-items:center; justify-content:space-between;
    padding:.625rem .875rem; border-top:1px solid #f3f4f6;
    font-size:.775rem; color:#6b7280;
}
.ac-page-btns { display:flex; gap:.25rem; }
.ac-page-btn {
    padding:.3rem .625rem; border:1px solid #d1d5db; border-radius:.25rem;
    background:#fff; font-size:.775rem; color:#374151;
    cursor:pointer; transition:background .1s;
}
.ac-page-btn:hover:not(:disabled) { background:#f9fafb; }
.ac-page-btn:disabled { opacity:.4; cursor:default; }
.ac-page-btn.active { background:#6d28d9; color:#fff; border-color:#6d28d9; }

/* ── Detail panel ──────────────────────────────────────────────────────────── */
.ac-detail {
    background:#fff; border:1px solid #e5e7eb; border-radius:.5rem;
    box-shadow:0 1px 2px rgba(0,0,0,.04);
}
.ac-detail-header {
    display:flex; align-items:flex-start; justify-content:space-between;
    padding:.875rem 1rem; border-bottom:1px solid #f3f4f6;
}
.ac-detail-title { font-size:.925rem; font-weight:600; color:#111827; line-height:1.3; }
.ac-detail-meta { font-size:.75rem; color:#6b7280; margin-top:.25rem; }
.ac-close-btn {
    background:none; border:none; cursor:pointer; color:#9ca3af;
    padding:.125rem; line-height:1; font-size:1rem; flex-shrink:0;
    transition:color .15s;
}
.ac-close-btn:hover { color:#374151; }
.ac-detail-body { padding:1rem; display:flex; flex-direction:column; gap:.875rem; }
.ac-detail-section { display:flex; flex-direction:column; gap:.375rem; }
.ac-detail-section-label { font-size:.68rem; font-weight:600; text-transform:uppercase; letter-spacing:.06em; color:#9ca3af; }
.ac-detail-row { display:flex; align-items:flex-start; gap:.5rem; }
.ac-detail-key { font-size:.775rem; color:#6b7280; width:6rem; flex-shrink:0; }
.ac-detail-val { font-size:.775rem; color:#111827; font-weight:500; }
.ac-detail-desc { font-size:.8rem; color:#374151; line-height:1.5; }
.ac-detail-actions { display:flex; gap:.5rem; flex-wrap:wrap; padding:.875rem 1rem; border-top:1px solid #f3f4f6; }
.ac-btn {
    padding:.375rem .875rem; border-radius:.375rem;
    font-size:.775rem; font-weight:500; cursor:pointer;
    border:1px solid transparent; transition:all .15s;
    display:inline-flex; align-items:center; gap:.375rem;
}
.ac-btn-primary   { background:#6d28d9; color:#fff; border-color:#6d28d9; }
.ac-btn-primary:hover { background:#5b21b6; }
.ac-btn-secondary { background:#fff; color:#374151; border-color:#d1d5db; }
.ac-btn-secondary:hover { background:#f9fafb; }
.ac-btn-danger    { background:#fff; color:#dc2626; border-color:#fca5a5; }
.ac-btn-danger:hover { background:#fef2f2; }
.ac-btn-success   { background:#fff; color:#16a34a; border-color:#86efac; }
.ac-btn-success:hover { background:#f0fdf4; }

/* ── Sidebar cards ─────────────────────────────────────────────────────────── */
.ac-sc { background:#fff; border:1px solid #e5e7eb; border-radius:.5rem; box-shadow:0 1px 2px rgba(0,0,0,.04); }
.ac-sc-head {
    padding:.625rem .875rem; border-bottom:1px solid #f3f4f6;
    font-size:.75rem; font-weight:600; color:#374151;
    display:flex; align-items:center; gap:.375rem;
}
.ac-sc-body { padding:.75rem .875rem; }

/* SLA donut */
.ac-donut-wrap { display:flex; align-items:center; gap:1rem; }
.ac-donut-legend { display:flex; flex-direction:column; gap:.375rem; flex:1; }
.ac-donut-item { display:flex; align-items:center; gap:.375rem; font-size:.75rem; color:#374151; }
.ac-donut-dot { width:.5rem; height:.5rem; border-radius:9999px; flex-shrink:0; }
.ac-donut-val { font-weight:700; margin-left:auto; }

/* By-type bars */
.ac-type-row { display:flex; flex-direction:column; gap:.5rem; }
.ac-type-item { display:flex; flex-direction:column; gap:.2rem; }
.ac-type-label-row { display:flex; justify-content:space-between; font-size:.72rem; color:#374151; }
.ac-type-bar-bg { background:#f3f4f6; border-radius:9999px; height:.35rem; }
.ac-type-bar { background:#6d28d9; border-radius:9999px; height:.35rem; transition:width .3s; }

/* Deadlines */
.ac-deadline-list { display:flex; flex-direction:column; gap:.5rem; }
.ac-deadline-item {
    display:flex; align-items:center; gap:.625rem;
    padding:.4rem .5rem; border-radius:.375rem;
    transition:background .1s; cursor:pointer;
}
.ac-deadline-item:hover { background:#fafafa; }
.ac-deadline-dot { width:.4rem; height:.4rem; border-radius:9999px; flex-shrink:0; }
.ac-deadline-info { flex:1; min-width:0; }
.ac-deadline-title { font-size:.75rem; font-weight:500; color:#111827; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.ac-deadline-time { font-size:.68rem; color:#6b7280; }
.ac-deadline-time.danger { color:#dc2626; font-weight:600; }

/* ── Modal overlay ─────────────────────────────────────────────────────────── */
.ac-modal-overlay {
    position:fixed; inset:0; background:rgba(0,0,0,.5);
    display:flex; align-items:center; justify-content:center;
    z-index:9999; padding:1rem;
}
.ac-modal {
    background:#fff; border-radius:.75rem; box-shadow:0 20px 60px rgba(0,0,0,.2);
    width:100%; max-width:26rem; padding:1.5rem;
    display:flex; flex-direction:column; gap:1rem;
}
.ac-modal-title { font-size:1rem; font-weight:700; color:#111827; }
.ac-modal-sub { font-size:.825rem; color:#6b7280; margin-top:.25rem; }
.ac-modal-label { font-size:.775rem; font-weight:500; color:#374151; margin-bottom:.375rem; display:block; }
.ac-modal-textarea {
    width:100%; padding:.5rem .625rem;
    border:1px solid #d1d5db; border-radius:.375rem;
    font-size:.825rem; color:#111827; resize:vertical; min-height:5rem;
    outline:none; transition:border-color .15s; box-sizing:border-box;
}
.ac-modal-textarea:focus { border-color:#7c3aed; }
.ac-modal-footer { display:flex; gap:.5rem; justify-content:flex-end; }
</style>

<div class="ac-wrap">

{{-- ── KPI CARDS ────────────────────────────────────────────────────────────── --}}
<div class="ac-kpi-row">
    <div class="ac-kpi">
        <div class="ac-kpi-label">Total Actions</div>
        <div class="ac-kpi-value">{{ $kpis['total'] }}</div>
        <div class="ac-kpi-sub">Open &amp; active</div>
    </div>
    <div class="ac-kpi">
        <div class="ac-kpi-label">Overdue</div>
        <div class="ac-kpi-value {{ $kpis['overdue'] > 0 ? 'danger' : '' }}">{{ $kpis['overdue'] }}</div>
        <div class="ac-kpi-sub">Past due date</div>
    </div>
    <div class="ac-kpi">
        <div class="ac-kpi-label">Due Today</div>
        <div class="ac-kpi-value {{ $kpis['due_today'] > 0 ? 'warning' : '' }}">{{ $kpis['due_today'] }}</div>
        <div class="ac-kpi-sub">Needs action today</div>
    </div>
    <div class="ac-kpi">
        <div class="ac-kpi-label">Due This Week</div>
        <div class="ac-kpi-value">{{ $kpis['due_week'] }}</div>
        <div class="ac-kpi-sub">By end of week</div>
    </div>
    <div class="ac-kpi">
        <div class="ac-kpi-label">Completed MTD</div>
        <div class="ac-kpi-value success">{{ $kpis['completed_mtd'] }}</div>
        <div class="ac-kpi-sub">This month</div>
    </div>
</div>

{{-- ── TOOLBAR ──────────────────────────────────────────────────────────────── --}}
<div class="ac-toolbar">
    <div class="ac-search-wrap">
        <svg class="ac-search-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35m0 0A7.5 7.5 0 104.5 4.5a7.5 7.5 0 0012.15 12.15z"/>
        </svg>
        <input type="text" class="ac-search" placeholder="Search actions…" wire:model.live.debounce.300ms="search">
    </div>

    <select class="ac-select" wire:model.live="typeFilter">
        <option value="">All Types</option>
        @foreach(\App\Models\Action::TYPE_LABELS as $val => $label)
            <option value="{{ $val }}">{{ $label }}</option>
        @endforeach
    </select>

    <select class="ac-select" wire:model.live="priorityFilter">
        <option value="">All Priorities</option>
        @foreach(\App\Models\Action::PRIORITY_LABELS as $val => $label)
            <option value="{{ $val }}">{{ $label }}</option>
        @endforeach
    </select>

    @if(count($assignees))
    <select class="ac-select" wire:model.live="assigneeFilter">
        <option value="">All Assignees</option>
        @foreach($assignees as $uid => $name)
            <option value="{{ $uid }}">{{ $name }}</option>
        @endforeach
    </select>
    @endif

    <div class="ac-spacer"></div>

    <a href="{{ route('filament.admin.pages.action-center', ['tenant' => filament()->getTenant()?->slug]) }}"
       wire:click.prevent="exportCsv"
       class="ac-export-btn">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
        </svg>
        Export CSV
    </a>
</div>

{{-- ── TABS ─────────────────────────────────────────────────────────────────── --}}
<div class="ac-tabs">
    @php
    $tabs = [
        ['key'=>'all',         'label'=>'All Actions'],
        ['key'=>'overdue',     'label'=>'Overdue',      'danger'=>true],
        ['key'=>'due_today',   'label'=>'Due Today'],
        ['key'=>'due_week',    'label'=>'Due This Week'],
        ['key'=>'in_progress', 'label'=>'In Progress'],
        ['key'=>'completed',   'label'=>'Completed'],
    ];
    @endphp
    @foreach($tabs as $tab)
    <button class="ac-tab {{ $this->activeTab === $tab['key'] ? 'active' : '' }}"
            wire:click="$set('activeTab', '{{ $tab['key'] }}')">
        {{ $tab['label'] }}
        @if(($tabCounts[$tab['key']] ?? 0) > 0)
        <span class="ac-tab-count {{ ($tab['danger'] ?? false) && ($tabCounts[$tab['key']] ?? 0) > 0 ? 'danger' : '' }}">
            {{ $tabCounts[$tab['key']] }}
        </span>
        @endif
    </button>
    @endforeach
</div>

{{-- ── MAIN: TABLE + SIDEBAR ────────────────────────────────────────────────── --}}
<div class="ac-main">

    {{-- LEFT: table + detail panel --}}
    <div class="ac-content-area">

        {{-- Table card --}}
        <div class="ac-card">
            <table class="ac-table">
                <thead>
                    <tr>
                        <th wire:click="$set('sortField','priority')"
                            class="{{ $this->sortField === 'priority' ? 'sorted' : '' }}">
                            Action
                            @if($this->sortField === 'priority')<span class="sort-icon">{{ $this->sortDir === 'asc' ? '↑' : '↓' }}</span>@endif
                        </th>
                        <th>Investigation</th>
                        <th>Type</th>
                        <th>Assigned To</th>
                        <th wire:click="$set('sortField','due_at')"
                            class="{{ $this->sortField === 'due_at' ? 'sorted' : '' }}">
                            Due Date
                            @if($this->sortField === 'due_at')<span class="sort-icon">{{ $this->sortDir === 'asc' ? '↑' : '↓' }}</span>@endif
                        </th>
                        <th>SLA</th>
                        <th wire:click="$set('sortField','status')"
                            class="{{ $this->sortField === 'status' ? 'sorted' : '' }}">
                            Status
                            @if($this->sortField === 'status')<span class="sort-icon">{{ $this->sortDir === 'asc' ? '↑' : '↓' }}</span>@endif
                        </th>
                    </tr>
                </thead>
                <tbody>
                @forelse($actions as $act)
                @php
                    $slaStatus = $act->getSlaStatus();
                    $invUrl    = $act->investigation_id
                        ? \App\Filament\Resources\InvestigationResource::getUrl('investigate', ['record' => $act->investigation_id])
                        : null;
                @endphp
                <tr wire:click="selectAction({{ $act->id }})"
                    class="{{ $this->selectedActionId === $act->id ? 'ac-row-selected' : '' }}">
                    <td style="max-width:240px">
                        <div class="ac-action-cell">
                            <span class="ac-pri-dot {{ $acPriClass($act->priority) }}"></span>
                            <div>
                                <div class="ac-action-title">{{ Str::limit($act->title, 55) }}</div>
                                @if($act->investigation?->primary_sku)
                                <div class="ac-action-sub">SKU: {{ $act->investigation->primary_sku }}</div>
                                @endif
                            </div>
                        </div>
                    </td>
                    <td>
                        @if($act->investigation)
                        <div style="font-size:.775rem;font-weight:500;color:#111827">
                            {{ Str::limit($act->investigation->title ?? 'Investigation #'.$act->investigation_id, 36) }}
                        </div>
                        @if($act->investigation->primaryStore)
                        <div class="ac-action-sub">{{ $act->investigation->primaryStore->name }}</div>
                        @endif
                        @else
                        <span style="color:#9ca3af;font-size:.775rem">—</span>
                        @endif
                    </td>
                    <td>
                        <span style="font-size:.775rem;color:#374151">{{ $act->getTypeLabel() }}</span>
                    </td>
                    <td>
                        @if($act->assignedTo)
                        <div class="ac-assignee">
                            <span class="ac-avatar">{{ strtoupper(substr($act->assignedTo->name, 0, 1)) }}</span>
                            {{ $act->assignedTo->name }}
                        </div>
                        @elseif($act->assignedTeam)
                        <div class="ac-assignee">
                            <span class="ac-avatar" style="background:#e0e7ff;color:#3730a3">T</span>
                            {{ $act->assignedTeam->name }}
                        </div>
                        @else
                        <span class="ac-unassigned">Unassigned</span>
                        @endif
                    </td>
                    <td>
                        <span style="font-size:.775rem;color:{{ $act->isOverdue() ? '#dc2626' : '#374151' }};font-weight:{{ $act->isOverdue() ? '600' : '400' }}">
                            {{ $acDueLabel($act->due_at?->toDateTimeString()) }}
                        </span>
                    </td>
                    <td>
                        <div class="ac-sla {{ $acSlaClass($slaStatus) }}">
                            <span class="ac-sla-dot"></span>
                            {{ $acSlaLabel($slaStatus) }}
                        </div>
                    </td>
                    <td>
                        <span class="ac-badge {{ $acStatusClass($act->status) }}">
                            {{ $act->getStatusLabel() }}
                        </span>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7">
                        <div class="ac-empty">
                            <div class="ac-empty-icon">✅</div>
                            No actions found for this view.
                        </div>
                    </td>
                </tr>
                @endforelse
                </tbody>
            </table>

            {{-- Pagination --}}
            @if($actions->total() > $this->perPage)
            <div class="ac-pagination">
                <span>
                    Showing {{ $actions->firstItem() }}–{{ $actions->lastItem() }} of {{ $actions->total() }}
                </span>
                <div class="ac-page-btns">
                    <button class="ac-page-btn"
                            wire:click="$set('currentPage', {{ max(1, $this->currentPage - 1) }})"
                            @if($this->currentPage <= 1) disabled @endif>←</button>
                    @for($p = max(1, $this->currentPage - 2); $p <= min($actions->lastPage(), $this->currentPage + 2); $p++)
                    <button class="ac-page-btn {{ $p === $this->currentPage ? 'active' : '' }}"
                            wire:click="$set('currentPage', {{ $p }})">{{ $p }}</button>
                    @endfor
                    <button class="ac-page-btn"
                            wire:click="$set('currentPage', {{ min($actions->lastPage(), $this->currentPage + 1) }})"
                            @if($this->currentPage >= $actions->lastPage()) disabled @endif>→</button>
                </div>
            </div>
            @endif
        </div>

        {{-- Detail panel --}}
        @if($selectedAct)
        <div class="ac-detail">
            <div class="ac-detail-header">
                <div style="flex:1;min-width:0">
                    <div class="ac-detail-title">{{ $selectedAct->title }}</div>
                    <div class="ac-detail-meta">
                        <span class="ac-badge {{ $acStatusClass($selectedAct->status) }}">{{ $selectedAct->getStatusLabel() }}</span>
                        &nbsp;
                        <span style="color:{{ ['critical'=>'#dc2626','high'=>'#f59e0b','medium'=>'#3b82f6'][$selectedAct->priority] ?? '#6b7280' }};font-weight:600;font-size:.72rem">
                            {{ strtoupper($selectedAct->getPriorityLabel()) }}
                        </span>
                        &nbsp;·&nbsp;
                        <span>{{ $selectedAct->getTypeLabel() }}</span>
                    </div>
                </div>
                <button class="ac-close-btn" wire:click="closePanel()">✕</button>
            </div>

            <div class="ac-detail-body">
                {{-- Description --}}
                @if($selectedAct->description)
                <div class="ac-detail-section">
                    <div class="ac-detail-section-label">Description</div>
                    <div class="ac-detail-desc">{{ $selectedAct->description }}</div>
                </div>
                @endif

                {{-- Key facts --}}
                <div class="ac-detail-section">
                    <div class="ac-detail-section-label">Details</div>
                    <div class="ac-detail-row">
                        <span class="ac-detail-key">Investigation</span>
                        @if($selectedAct->investigation)
                        <a href="{{ \App\Filament\Resources\InvestigationResource::getUrl('investigate', ['record' => $selectedAct->investigation_id]) }}"
                           class="ac-detail-val" style="color:#6d28d9;text-decoration:none"
                           onclick="event.stopPropagation()">
                            {{ Str::limit($selectedAct->investigation->title ?? '#'.$selectedAct->investigation_id, 45) }} →
                        </a>
                        @else
                        <span class="ac-detail-val">—</span>
                        @endif
                    </div>
                    <div class="ac-detail-row">
                        <span class="ac-detail-key">Store</span>
                        <span class="ac-detail-val">{{ $selectedAct->investigation?->primaryStore?->name ?? '—' }}</span>
                    </div>
                    <div class="ac-detail-row">
                        <span class="ac-detail-key">Assigned To</span>
                        <span class="ac-detail-val">
                            {{ $selectedAct->assignedTo?->name ?? $selectedAct->assignedTeam?->name ?? 'Unassigned' }}
                        </span>
                    </div>
                    <div class="ac-detail-row">
                        <span class="ac-detail-key">Due</span>
                        <span class="ac-detail-val" style="{{ $selectedAct->isOverdue() ? 'color:#dc2626' : '' }}">
                            {{ $selectedAct->due_at ? $selectedAct->due_at->format('D M j, Y H:i') : '—' }}
                            @if($selectedAct->isOverdue()) <span style="font-size:.7rem;font-weight:700">(OVERDUE)</span> @endif
                        </span>
                    </div>
                    <div class="ac-detail-row">
                        <span class="ac-detail-key">Created</span>
                        <span class="ac-detail-val">{{ $selectedAct->created_at?->format('M j, Y') }}</span>
                    </div>
                    @if($selectedAct->acknowledged_at)
                    <div class="ac-detail-row">
                        <span class="ac-detail-key">Acknowledged</span>
                        <span class="ac-detail-val">{{ $selectedAct->acknowledged_at->format('M j, Y H:i') }}</span>
                    </div>
                    @endif
                    @if($selectedAct->completed_at)
                    <div class="ac-detail-row">
                        <span class="ac-detail-key">Completed</span>
                        <span class="ac-detail-val">{{ $selectedAct->completed_at->format('M j, Y H:i') }}</span>
                    </div>
                    @endif
                </div>

                {{-- Notes --}}
                @if($selectedAct->notes || $selectedAct->completion_notes)
                <div class="ac-detail-section">
                    @if($selectedAct->notes)
                    <div class="ac-detail-section-label">Notes</div>
                    <div class="ac-detail-desc">{{ $selectedAct->notes }}</div>
                    @endif
                    @if($selectedAct->completion_notes)
                    <div class="ac-detail-section-label" style="margin-top:.5rem">Completion Notes</div>
                    <div class="ac-detail-desc">{{ $selectedAct->completion_notes }}</div>
                    @endif
                </div>
                @endif

                {{-- Investigation anomaly context --}}
                @if($selectedAct->investigation?->anomalies?->count())
                <div class="ac-detail-section">
                    <div class="ac-detail-section-label">Related Anomalies</div>
                    @foreach($selectedAct->investigation->anomalies->take(3) as $anom)
                    <div class="ac-detail-row">
                        <span class="ac-detail-key">{{ ucfirst($anom->severity ?? 'anomaly') }}</span>
                        <span class="ac-detail-val">{{ Str::limit($anom->getRuleLabel() ?? $anom->rule_type, 40) }}</span>
                    </div>
                    @endforeach
                </div>
                @endif
            </div>

            {{-- Action buttons --}}
            <div class="ac-detail-actions">
                @if($selectedAct->status === \App\Models\Action::STATUS_UNASSIGNED || $selectedAct->status === \App\Models\Action::STATUS_ASSIGNED)
                <button class="ac-btn ac-btn-secondary" wire:click="markAcknowledged({{ $selectedAct->id }})">
                    ✓ Acknowledge
                </button>
                @endif

                @if(in_array($selectedAct->status, [\App\Models\Action::STATUS_ACKNOWLEDGED, \App\Models\Action::STATUS_ASSIGNED]))
                <button class="ac-btn ac-btn-primary" wire:click="markInProgress({{ $selectedAct->id }})">
                    ▶ Start
                </button>
                @endif

                @if($selectedAct->isActive())
                <button class="ac-btn ac-btn-success" wire:click="openCompleteModal({{ $selectedAct->id }})">
                    ✅ Complete
                </button>
                <button class="ac-btn ac-btn-danger" wire:click="cancelAction({{ $selectedAct->id }})">
                    ✕ Cancel
                </button>
                @endif
            </div>
        </div>
        @endif
    </div>

    {{-- RIGHT: sidebar --}}
    <div class="ac-sidebar">

        {{-- SLA Compliance donut --}}
        <div class="ac-sc">
            <div class="ac-sc-head">
                <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                SLA Compliance
            </div>
            <div class="ac-sc-body">
                @php
                $slaTotal = array_sum($slaData);
                $onPct    = $slaTotal ? round($slaData['on_track'] / $slaTotal * 100) : 0;
                $atPct    = $slaTotal ? round($slaData['at_risk']  / $slaTotal * 100) : 0;
                $ovPct    = $slaTotal ? round($slaData['overdue']  / $slaTotal * 100) : 0;

                // Build SVG donut
                $r = 28; $cx = 36; $cy = 36; $circ = 2 * M_PI * $r;
                $segs = [
                    ['pct'=>$onPct, 'color'=>'#16a34a'],
                    ['pct'=>$atPct, 'color'=>'#f59e0b'],
                    ['pct'=>$ovPct, 'color'=>'#dc2626'],
                ];
                $offset = 0;
                $paths  = '';
                foreach ($segs as $seg) {
                    $dash   = ($seg['pct'] / 100) * $circ;
                    $gap    = $circ - $dash;
                    $paths .= '<circle cx="'.$cx.'" cy="'.$cy.'" r="'.$r.'" fill="none" stroke="'.$seg['color'].'" stroke-width="8"'
                        .' stroke-dasharray="'.$dash.' '.$gap.'"'
                        .' stroke-dashoffset="'.(-$offset).'"'
                        .' transform="rotate(-90 '.$cx.' '.$cy.')"/>';
                    $offset += $dash;
                }
                @endphp
                <div class="ac-donut-wrap">
                    <svg width="72" height="72" viewBox="0 0 72 72" style="flex-shrink:0">
                        <circle cx="{{ $cx }}" cy="{{ $cy }}" r="{{ $r }}" fill="none" stroke="#f3f4f6" stroke-width="8"/>
                        {!! $paths !!}
                        <text x="{{ $cx }}" y="{{ $cy+1 }}" text-anchor="middle" dominant-baseline="middle"
                              font-size="11" font-weight="700" fill="#111827">{{ $onPct }}%</text>
                    </svg>
                    <div class="ac-donut-legend">
                        <div class="ac-donut-item">
                            <span class="ac-donut-dot" style="background:#16a34a"></span>
                            On Track
                            <span class="ac-donut-val">{{ $slaData['on_track'] }}</span>
                        </div>
                        <div class="ac-donut-item">
                            <span class="ac-donut-dot" style="background:#f59e0b"></span>
                            At Risk
                            <span class="ac-donut-val">{{ $slaData['at_risk'] }}</span>
                        </div>
                        <div class="ac-donut-item">
                            <span class="ac-donut-dot" style="background:#dc2626"></span>
                            Overdue
                            <span class="ac-donut-val" style="color:#dc2626;font-weight:700">{{ $slaData['overdue'] }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Actions by Type --}}
        <div class="ac-sc">
            <div class="ac-sc-head">
                <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                </svg>
                Actions by Type
            </div>
            <div class="ac-sc-body">
                @if(count($byType))
                @php $maxType = max(array_column($byType, 'count') ?: [1]); @endphp
                <div class="ac-type-row">
                    @foreach($byType as $bt)
                    <div class="ac-type-item">
                        <div class="ac-type-label-row">
                            <span>{{ $bt['label'] }}</span>
                            <span style="font-weight:600">{{ $bt['count'] }}</span>
                        </div>
                        <div class="ac-type-bar-bg">
                            <div class="ac-type-bar" style="width:{{ $maxType ? round($bt['count']/$maxType*100) : 0 }}%"></div>
                        </div>
                    </div>
                    @endforeach
                </div>
                @else
                <div style="font-size:.775rem;color:#9ca3af;text-align:center;padding:.75rem 0">No active actions</div>
                @endif
            </div>
        </div>

        {{-- Upcoming Deadlines --}}
        <div class="ac-sc">
            <div class="ac-sc-head">
                <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
                Upcoming Deadlines
            </div>
            <div class="ac-sc-body">
                @if(count($deadlines))
                <div class="ac-deadline-list">
                    @foreach($deadlines as $dl)
                    @php
                        $dlDue  = $dl['due_at'] ? \Carbon\Carbon::parse($dl['due_at']) : null;
                        $isOv   = $dlDue && $dlDue->isPast();
                        $dlDot  = $isOv ? '#dc2626' : ($dlDue && $dlDue->diffInHours(now()) < 4 ? '#f59e0b' : '#16a34a');
                    @endphp
                    <div class="ac-deadline-item" wire:click="selectAction({{ $dl['id'] }})">
                        <span class="ac-deadline-dot" style="background:{{ $dlDot }}"></span>
                        <div class="ac-deadline-info">
                            <div class="ac-deadline-title">{{ \Illuminate\Support\Str::limit($dl['title'], 34) }}</div>
                            <div class="ac-deadline-time {{ $isOv ? 'danger' : '' }}">
                                {{ $dlDue ? ($isOv ? 'Overdue: '.$dlDue->format('M j H:i') : $dlDue->format('M j, H:i')) : '—' }}
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
                @else
                <div style="font-size:.775rem;color:#9ca3af;text-align:center;padding:.75rem 0">No deadlines in next 3 days</div>
                @endif
            </div>
        </div>

    </div>{{-- /sidebar --}}
</div>{{-- /main --}}

</div>{{-- /wrap --}}

{{-- ── COMPLETION MODAL ─────────────────────────────────────────────────────── --}}
@if($this->showCompleteModal)
<div class="ac-modal-overlay" wire:click.self="closeCompleteModal">
    <div class="ac-modal">
        <div>
            <div class="ac-modal-title">Complete Action</div>
            <div class="ac-modal-sub">Add completion notes before marking this action as done.</div>
        </div>

        <div>
            <label class="ac-modal-label" for="completion_notes">
                Completion Notes <span style="color:#dc2626">*</span>
            </label>
            <textarea id="completion_notes"
                      class="ac-modal-textarea"
                      wire:model.live="completionNotes"
                      placeholder="What was done? What was the outcome?…"
                      rows="4"></textarea>
        </div>

        <div class="ac-modal-footer">
            <button class="ac-btn ac-btn-secondary" wire:click="closeCompleteModal">Cancel</button>
            <button class="ac-btn ac-btn-primary"
                    wire:click="confirmComplete"
                    @if(strlen(trim($this->completionNotes)) < 5) disabled style="opacity:.5;cursor:not-allowed" @endif>
                ✅ Mark Complete
            </button>
        </div>
    </div>
</div>
@endif

</x-filament-panels::page>
