<x-filament-panels::page>
<style>
/* ── Reset icon sizes Filament may not have in its bundle ─────────────── */
.inv-icon { display:inline-block; flex-shrink:0; }
.inv-icon svg,.inv-icon-sm svg { width:1rem;height:1rem; }
.inv-icon-md svg { width:1.25rem;height:1.25rem; }
.inv-icon-lg svg { width:2rem;height:2rem; }

/* ── Layout ───────────────────────────────────────────────────────────── */
.inv-stats   { display:grid; grid-template-columns:repeat(2,1fr); gap:.75rem; margin-bottom:1.25rem; }
.inv-grid    { display:grid; grid-template-columns:1fr; gap:1.25rem; }
@media(min-width:768px)  { .inv-stats { grid-template-columns:repeat(3,1fr); } }
@media(min-width:1024px) { .inv-stats { grid-template-columns:repeat(5,1fr); } .inv-grid { grid-template-columns:2fr 1fr; } }

/* ── Cards ────────────────────────────────────────────────────────────── */
.inv-card {
    background:#fff;
    border:1px solid #e5e7eb;
    border-radius:.75rem;
    box-shadow:0 1px 3px rgba(0,0,0,.06);
    overflow:hidden;
}
.inv-card-head {
    display:flex; align-items:center; gap:.5rem;
    padding:.625rem 1.25rem;
    border-bottom:1px solid #e5e7eb;
    background:#f9fafb;
}
.inv-card-head-title { font-size:.8125rem; font-weight:600; color:#374151; }
.inv-card-body { padding:1rem 1.25rem; }

/* ── Stat tile ────────────────────────────────────────────────────────── */
.inv-stat {
    background:#fff;
    border:1px solid #e5e7eb;
    border-radius:.75rem;
    padding:.875rem 1rem;
    box-shadow:0 1px 3px rgba(0,0,0,.06);
}
.inv-stat-label { font-size:.6875rem; font-weight:600; text-transform:uppercase; letter-spacing:.05em; color:#9ca3af; margin-bottom:.25rem; }
.inv-stat-value { font-size:1.375rem; font-weight:700; color:#111827; line-height:1.2; }
.inv-stat-sub   { font-size:.8125rem; font-weight:500; color:#374151; margin-top:.1rem; }

/* ── AI Narrative ─────────────────────────────────────────────────────── */
.inv-ai {
    background:linear-gradient(135deg,#eef2ff 0%,#f5f3ff 100%);
    border:1px solid #c7d2fe;
    border-radius:.75rem;
    padding:1.125rem 1.25rem;
    margin-bottom:1.25rem;
    box-shadow:0 1px 3px rgba(0,0,0,.06);
}
.inv-ai-head { display:flex; align-items:center; gap:.5rem; margin-bottom:.75rem; }
.inv-ai-title { font-size:.8125rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#4338ca; flex:1; }
.inv-ai-section { padding-top:.75rem; margin-top:.75rem; border-top:1px solid #c7d2fe; }
.inv-ai-section-label { font-size:.6875rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#6366f1; margin-bottom:.25rem; }
.inv-ai-text  { font-size:.875rem; color:#1f2937; line-height:1.6; }
.inv-ai-empty {
    border:2px dashed #d1d5db;
    border-radius:.75rem;
    padding:2rem 1.25rem;
    margin-bottom:1.25rem;
    text-align:center;
    color:#9ca3af;
    font-size:.875rem;
}

/* ── Badges ───────────────────────────────────────────────────────────── */
.inv-badge {
    display:inline-flex; align-items:center;
    padding:.2rem .6rem;
    border-radius:9999px;
    font-size:.75rem; font-weight:600;
    white-space:nowrap;
}
.badge-warning  { background:#fef3c7; color:#92400e; }
.badge-danger   { background:#fee2e2; color:#991b1b; }
.badge-info     { background:#dbeafe; color:#1e40af; }
.badge-success  { background:#dcfce7; color:#166534; }
.badge-gray     { background:#f3f4f6; color:#374151; }
.badge-purple   { background:#ede9fe; color:#6d28d9; }

/* ── Divide rows ──────────────────────────────────────────────────────── */
.inv-row { display:flex; align-items:flex-start; justify-content:space-between; gap:1rem; padding:.75rem 1.25rem; border-bottom:1px solid #f3f4f6; }
.inv-row:last-child { border-bottom:none; }
.inv-row-title { font-size:.875rem; font-weight:500; color:#111827; }
.inv-row-sub   { font-size:.75rem; color:#6b7280; margin-top:.125rem; line-height:1.4; }

/* ── Activity timeline ────────────────────────────────────────────────── */
.inv-timeline { padding:1rem 1.25rem; max-height:20rem; overflow-y:auto; }
.inv-timeline-item { display:flex; gap:.75rem; padding-bottom:.875rem; }
.inv-timeline-dot  { flex-shrink:0; width:.5rem; height:.5rem; border-radius:50%; background:#d1d5db; margin-top:.375rem; }
.inv-timeline-body { min-width:0; }
.inv-timeline-text { font-size:.8125rem; color:#374151; line-height:1.5; }
.inv-timeline-meta { font-size:.75rem; color:#9ca3af; margin-top:.125rem; }

/* ── Outcome financials ───────────────────────────────────────────────── */
.inv-financials { display:grid; grid-template-columns:repeat(3,1fr); gap:.5rem; text-align:center; }
.inv-fin-label  { font-size:.6875rem; text-transform:uppercase; letter-spacing:.05em; color:#9ca3af; margin-bottom:.25rem; }
.inv-fin-value  { font-size:1.125rem; font-weight:700; }

/* ── Risk / recovery colours ──────────────────────────────────────────── */
.color-risk     { color:#dc2626; }
.color-recovery { color:#16a34a; }
.color-rate     { color:#2563eb; }
.color-muted    { color:#9ca3af; }

/* ── Revenue at risk callout ──────────────────────────────────────────── */
.inv-risk-callout {
    display:flex; align-items:center; justify-content:space-between;
    background:#fef2f2; border:1px solid #fecaca;
    border-radius:.5rem; padding:.625rem 1rem; margin-top:.75rem;
}
.inv-risk-label { font-size:.75rem; font-weight:600; text-transform:uppercase; letter-spacing:.05em; color:#dc2626; }
.inv-risk-value { font-size:1.25rem; font-weight:800; color:#dc2626; }

/* ── Dark mode ────────────────────────────────────────────────────────── */
.dark .inv-card       { background:#1f2937; border-color:#374151; }
.dark .inv-card-head  { background:#111827; border-color:#374151; }
.dark .inv-card-head-title { color:#d1d5db; }
.dark .inv-stat       { background:#1f2937; border-color:#374151; }
.dark .inv-stat-label { color:#6b7280; }
.dark .inv-stat-value { color:#f9fafb; }
.dark .inv-stat-sub   { color:#d1d5db; }
.dark .inv-ai         { background:linear-gradient(135deg,#1e1b4b 0%,#2e1065 100%); border-color:#4338ca; }
.dark .inv-ai-title   { color:#a5b4fc; }
.dark .inv-ai-section { border-color:#4338ca; }
.dark .inv-ai-section-label { color:#818cf8; }
.dark .inv-ai-text    { color:#e5e7eb; }
.dark .inv-ai-empty   { border-color:#374151; color:#6b7280; }
.dark .inv-row        { border-color:#1f2937; }
.dark .inv-row-title  { color:#f9fafb; }
.dark .inv-row-sub    { color:#9ca3af; }
.dark .inv-timeline-dot  { background:#4b5563; }
.dark .inv-timeline-text { color:#d1d5db; }
.dark .inv-timeline-meta { color:#6b7280; }
.dark .inv-risk-callout  { background:#450a0a; border-color:#991b1b; }
.dark .inv-risk-label    { color:#f87171; }
.dark .inv-risk-value    { color:#f87171; }
.dark .inv-fin-label     { color:#6b7280; }
.dark .badge-warning  { background:#451a03; color:#fbbf24; }
.dark .badge-danger   { background:#450a0a; color:#f87171; }
.dark .badge-info     { background:#172554; color:#93c5fd; }
.dark .badge-success  { background:#052e16; color:#4ade80; }
.dark .badge-gray     { background:#374151; color:#d1d5db; }
.dark .badge-purple   { background:#2e1065; color:#c4b5fd; }
</style>

@php
    $statusBadge = match($record->status) {
        'open'        => 'badge-warning',
        'in_progress' => 'badge-info',
        'resolved'    => 'badge-success',
        'closed'      => 'badge-gray',
        default       => 'badge-gray',
    };
    $priorityBadge = match($record->priority) {
        'critical' => 'badge-danger',
        'high'     => 'badge-warning',
        'medium'   => 'badge-info',
        default    => 'badge-gray',
    };
    $confBadge = match($record->ai_confidence ?? '') {
        'established' => 'badge-success',
        'probable'    => 'badge-info',
        'suspected'   => 'badge-warning',
        default       => 'badge-gray',
    };
    $hoursOpen = $record->opened_at
        ? round(now()->diffInMinutes($record->opened_at) / 60, 1)
        : null;
@endphp

{{-- ── Stat strip ──────────────────────────────────────────────────────── --}}
<div class="inv-stats">
    <div class="inv-stat">
        <div class="inv-stat-label">Status</div>
        <div><span class="inv-badge {{ $statusBadge }}">{{ ucwords(str_replace('_',' ',$record->status)) }}</span></div>
    </div>
    <div class="inv-stat">
        <div class="inv-stat-label">Priority</div>
        <div><span class="inv-badge {{ $priorityBadge }}">{{ ucfirst($record->priority) }}</span></div>
    </div>
    <div class="inv-stat">
        <div class="inv-stat-label">Anomalies Linked</div>
        <div class="inv-stat-value">{{ $record->anomaly_count }}</div>
    </div>
    <div class="inv-stat">
        <div class="inv-stat-label">Time Open</div>
        @if($hoursOpen !== null)
            <div class="inv-stat-value" style="font-size:1.125rem">{{ $hoursOpen > 24 ? round($hoursOpen/24,1).'d' : $hoursOpen.'h' }}</div>
        @else
            <div class="inv-stat-sub" style="color:#9ca3af">—</div>
        @endif
    </div>
    <div class="inv-stat">
        <div class="inv-stat-label">Assigned To</div>
        <div class="inv-stat-sub">{{ $record->assignedTeam?->name ?? $record->assignedUser?->name ?? 'Unassigned' }}</div>
    </div>
</div>

{{-- ── AI Narrative ─────────────────────────────────────────────────────── --}}
@if($record->ai_summary)
<div class="inv-ai">
    <div class="inv-ai-head">
        <span class="inv-icon-md" style="color:#6366f1">
            <x-heroicon-o-sparkles />
        </span>
        <span class="inv-ai-title">AI Narrative</span>
        @if($record->ai_confidence)
            <span class="inv-badge {{ $confBadge }}">{{ ucfirst($record->ai_confidence) }} confidence</span>
        @endif
    </div>

    <p class="inv-ai-text">{{ $record->ai_summary }}</p>

    @if($record->ai_root_cause)
    <div class="inv-ai-section">
        <div class="inv-ai-section-label">Root Cause</div>
        <p class="inv-ai-text">{{ $record->ai_root_cause }}</p>
    </div>
    @endif

    @if($record->ai_recommended_action)
    <div class="inv-ai-section">
        <div class="inv-ai-section-label">Recommended Action</div>
        <p class="inv-ai-text" style="font-weight:500">{{ $record->ai_recommended_action }}</p>
    </div>
    @endif

    @if($record->revenue_at_risk)
    <div class="inv-risk-callout">
        <span class="inv-risk-label">Estimated Revenue at Risk</span>
        <span class="inv-risk-value">${{ number_format($record->revenue_at_risk, 2) }}</span>
    </div>
    @endif

    <p style="font-size:.75rem;color:#9ca3af;margin-top:.625rem">Generated {{ $record->ai_generated_at?->diffForHumans() }}</p>
</div>
@else
<div class="inv-ai-empty">
    <div style="margin-bottom:.5rem;font-size:1.5rem">✨</div>
    <p>No AI narrative yet — click <strong>Generate Narrative</strong> above.</p>
</div>
@endif

{{-- ── Main grid ────────────────────────────────────────────────────────── --}}
<div class="inv-grid">

    {{-- ── LEFT: Anomalies + Evidence ─────────────────────────────────── --}}
    <div style="display:flex;flex-direction:column;gap:1.25rem">

        {{-- Linked Anomalies --}}
        <div class="inv-card">
            <div class="inv-card-head">
                <span class="inv-icon" style="color:#f59e0b"><x-heroicon-o-exclamation-triangle /></span>
                <span class="inv-card-head-title">Linked Anomalies ({{ $record->anomalies->count() }})</span>
            </div>
            @forelse($record->anomalies as $anomaly)
            @php
                $sevBadge = match($anomaly->severity) {
                    'high'   => 'badge-danger',
                    'medium' => 'badge-warning',
                    default  => 'badge-gray',
                };
            @endphp
            <div class="inv-row">
                <div style="min-width:0;flex:1">
                    <div class="inv-row-title">
                        {{ $anomaly->getRuleLabel() }}
                        @if($anomaly->sku)<span style="color:#9ca3af;font-weight:400"> · {{ $anomaly->sku }}</span>@endif
                    </div>
                    @if($anomaly->description)
                    <div class="inv-row-sub" style="overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical">{{ $anomaly->description }}</div>
                    @endif
                </div>
                <span class="inv-badge {{ $sevBadge }}" style="flex-shrink:0">{{ ucfirst($anomaly->severity) }}</span>
            </div>
            @empty
            <div style="padding:1rem 1.25rem;font-size:.875rem;color:#9ca3af">No anomalies linked.</div>
            @endforelse
        </div>

        {{-- Evidence --}}
        <div class="inv-card">
            <div class="inv-card-head">
                <span class="inv-icon" style="color:#3b82f6"><x-heroicon-o-beaker /></span>
                <span class="inv-card-head-title">Evidence Package ({{ $record->evidence->count() }} items)</span>
            </div>
            @forelse($record->evidence->sortBy('direction') as $ev)
            <div class="inv-row">
                <div style="min-width:0;flex:1">
                    <div class="inv-row-title" style="font-size:.8125rem">{{ $ev->label }}</div>
                    <div class="inv-row-sub">{{ $ev->source }}</div>
                </div>
                <div style="text-align:right;flex-shrink:0">
                    <div style="font-size:.9375rem;font-weight:600;color:#111827">{{ $ev->getFormattedValue() }}</div>
                    @php
                        $dirColor = match($ev->direction) {
                            'supports'    => '#dc2626',
                            'contradicts' => '#16a34a',
                            default       => '#9ca3af',
                        };
                    @endphp
                    <div style="font-size:.75rem;color:{{ $dirColor }}">{{ ucfirst($ev->direction) }}</div>
                </div>
            </div>
            @empty
            <div style="padding:1rem 1.25rem;font-size:.875rem;color:#9ca3af">No evidence collected yet.</div>
            @endforelse
        </div>

    </div>

    {{-- ── RIGHT: Actions + Activity + Outcome ────────────────────────── --}}
    <div style="display:flex;flex-direction:column;gap:1.25rem">

        {{-- Remediation Actions --}}
        <div class="inv-card">
            <div class="inv-card-head">
                <span class="inv-icon" style="color:#22c55e"><x-heroicon-o-clipboard-document-check /></span>
                <span class="inv-card-head-title">Actions ({{ $record->actions->count() }})</span>
            </div>
            @forelse($record->actions as $act)
            @php
                $actBadge = match($act->status) {
                    'completed'   => 'badge-success',
                    'in_progress' => 'badge-info',
                    'cancelled'   => 'badge-gray',
                    default       => 'badge-warning',
                };
            @endphp
            <div class="inv-row" style="flex-direction:column;align-items:flex-start;gap:.375rem">
                <div style="display:flex;align-items:center;justify-content:space-between;width:100%;gap:.5rem">
                    <span class="inv-row-title">{{ $act->title }}</span>
                    <span class="inv-badge {{ $actBadge }}" style="flex-shrink:0">{{ ucwords(str_replace('_',' ',$act->status)) }}</span>
                </div>
                <div class="inv-row-sub">{{ $act->getTypeLabel() }}</div>
                @if($act->assignedTo)
                <div class="inv-row-sub">→ {{ $act->assignedTo->name }}</div>
                @endif
            </div>
            @empty
            <div style="padding:1rem 1.25rem;font-size:.875rem;color:#9ca3af">No actions yet — use <strong>Add Action</strong> above.</div>
            @endforelse
        </div>

        {{-- Activity --}}
        <div class="inv-card">
            <div class="inv-card-head">
                <span class="inv-icon" style="color:#9ca3af"><x-heroicon-o-clock /></span>
                <span class="inv-card-head-title">Activity</span>
            </div>
            <div class="inv-timeline">
                @forelse($record->auditLogs->sortByDesc('created_at')->take(20) as $log)
                <div class="inv-timeline-item">
                    <div class="inv-timeline-dot"></div>
                    <div class="inv-timeline-body">
                        <p class="inv-timeline-text">{{ $log->description }}</p>
                        <p class="inv-timeline-meta">{{ $log->getActorLabel() }} · {{ $log->created_at?->diffForHumans() }}</p>
                    </div>
                </div>
                @empty
                <p style="font-size:.875rem;color:#9ca3af">No activity yet.</p>
                @endforelse
            </div>
        </div>

        {{-- Resolution Notes --}}
        @if($record->resolution_notes)
        <div class="inv-card" style="border-color:#bbf7d0;background:#f0fdf4">
            <div class="inv-card-head" style="background:#dcfce7;border-color:#bbf7d0">
                <span class="inv-icon" style="color:#16a34a"><x-heroicon-o-check-circle /></span>
                <span class="inv-card-head-title" style="color:#166534">Resolution</span>
                @if($record->resolved_at)
                <span style="margin-left:auto;font-size:.75rem;color:#16a34a">{{ $record->resolved_at->diffForHumans() }}</span>
                @endif
            </div>
            <div class="inv-card-body">
                <p style="font-size:.875rem;color:#166534;line-height:1.6">{{ $record->resolution_notes }}</p>
            </div>
        </div>
        @endif

        {{-- Financial Outcome --}}
        @if($record->outcome)
        @php $outcome = $record->outcome;
            $otColors = [
                'resolved'         => 'badge-success',
                'false_positive'   => 'badge-gray',
                'duplicate'        => 'badge-gray',
                'escalated_to_ops' => 'badge-warning',
                'no_action_needed' => 'badge-info',
            ];
        @endphp
        <div class="inv-card">
            <div class="inv-card-head">
                <span class="inv-icon" style="color:#22c55e"><x-heroicon-o-currency-dollar /></span>
                <span class="inv-card-head-title">Financial Outcome</span>
                <span class="inv-badge {{ $otColors[$outcome->outcome_type] ?? 'badge-gray' }}" style="margin-left:auto">
                    {{ $outcome->getOutcomeTypeLabel() }}
                </span>
            </div>
            <div class="inv-card-body">
                <div class="inv-financials">
                    <div>
                        <div class="inv-fin-label">At Risk</div>
                        <div class="inv-fin-value color-risk">
                            {{ $outcome->revenue_at_risk ? '$'.number_format($outcome->revenue_at_risk,2) : '—' }}
                        </div>
                    </div>
                    <div>
                        <div class="inv-fin-label">Recovered</div>
                        <div class="inv-fin-value color-recovery">
                            {{ $outcome->observed_recovery ? '$'.number_format($outcome->observed_recovery,2) : '—' }}
                        </div>
                    </div>
                    <div>
                        <div class="inv-fin-label">Rate</div>
                        <div class="inv-fin-value color-rate">
                            {{ $outcome->getRecoveryRate() !== null ? $outcome->getRecoveryRate().'%' : '—' }}
                        </div>
                    </div>
                </div>

                @if($outcome->confirmed_root_cause)
                <div style="border-top:1px solid #e5e7eb;margin-top:.875rem;padding-top:.875rem">
                    <div style="font-size:.6875rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#9ca3af;margin-bottom:.25rem">Confirmed Root Cause</div>
                    <p style="font-size:.8125rem;color:#374151;line-height:1.5">{{ $outcome->confirmed_root_cause }}</p>
                    @if($outcome->ai_root_cause_correct !== null)
                    <p style="font-size:.75rem;margin-top:.25rem;color:{{ $outcome->ai_root_cause_correct ? '#16a34a' : '#d97706' }}">
                        AI root cause: {{ $outcome->ai_root_cause_correct ? '✓ Correct' : '✗ Incorrect' }}
                    </p>
                    @endif
                </div>
                @endif

                @if($outcome->recovery_method)
                <p style="font-size:.75rem;color:#9ca3af;margin-top:.5rem">Method: {{ $outcome->getRecoveryMethodLabel() }}</p>
                @endif
                <p style="font-size:.75rem;color:#9ca3af;margin-top:.25rem">
                    Recorded by {{ $outcome->recordedBy?->name ?? 'System' }} · {{ $outcome->recorded_at?->diffForHumans() }}
                </p>
            </div>
        </div>
        @endif

    </div>
</div>

</x-filament-panels::page>
