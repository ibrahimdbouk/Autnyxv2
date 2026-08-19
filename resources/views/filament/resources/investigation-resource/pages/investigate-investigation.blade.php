<x-filament-panels::page>
<style>
/* ═══════════════════════════════════════════════════════════════════════════
   inv2-* — Investigation V2 Detail Page
   All classes prefixed with inv2- to avoid conflicts with Filament or older
   inv- classes.
   ═══════════════════════════════════════════════════════════════════════════ */

/* ── Resets & base ─────────────────────────────────────────────────────── */
.inv2-icon svg { display:inline-block; width:1rem; height:1rem; vertical-align:middle; }
.inv2-icon-md svg { width:1.25rem; height:1.25rem; }

/* ── Info bar card ─────────────────────────────────────────────────────── */
.inv2-infobar {
    background:#fff;
    border:1px solid #e5e7eb;
    border-radius:.875rem;
    box-shadow:0 1px 4px rgba(0,0,0,.07);
    padding:1.25rem 1.5rem;
    margin-bottom:1.5rem;
    display:flex;
    flex-direction:column;
    gap:1rem;
}
@media(min-width:768px) {
    .inv2-infobar { flex-direction:row; gap:2rem; }
}
.inv2-infobar-left  { flex:1; min-width:0; display:flex; flex-direction:column; gap:.875rem; }
.inv2-infobar-right { flex-shrink:0; display:flex; flex-direction:column; gap:.5rem; min-width:220px; }
.inv2-badge-group { display:flex; flex-direction:column; gap:.25rem; }
.inv2-badge-group-label { font-size:.65rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#9ca3af; }
.inv2-badges-row { display:flex; flex-wrap:wrap; gap:.5rem; align-items:center; }

/* ── Badges ─────────────────────────────────────────────────────────────── */
.inv2-badge {
    display:inline-flex; align-items:center; gap:.25rem;
    padding:.2rem .65rem;
    border-radius:9999px;
    font-size:.75rem; font-weight:600;
    white-space:nowrap;
    line-height:1.4;
}
.inv2-badge-danger   { background:#fee2e2; color:#991b1b; }
.inv2-badge-warning  { background:#fef3c7; color:#92400e; }
.inv2-badge-info     { background:#dbeafe; color:#1e40af; }
.inv2-badge-success  { background:#dcfce7; color:#166534; }
.inv2-badge-gray     { background:#f3f4f6; color:#374151; }
.inv2-badge-purple   { background:#ede9fe; color:#6d28d9; }

/* ── Info bar description & divider ─────────────────────────────────────── */
.inv2-infobar-desc { font-size:.875rem; color:#374151; line-height:1.65; }
.inv2-infobar-divider { height:1px; background:#f3f4f6; margin:.5rem 0; }
.inv2-infobar-right-row { display:flex; justify-content:space-between; gap:.5rem; }
.inv2-infobar-right-label { font-size:.75rem; color:#9ca3af; font-weight:500; white-space:nowrap; }
.inv2-infobar-right-value { font-size:.8125rem; color:#374151; font-weight:600; text-align:right; }

/* ── 2-column main grid ─────────────────────────────────────────────────── */
.inv2-main-grid {
    display:grid;
    grid-template-columns:1fr;
    gap:1.25rem;
    margin-bottom:1.25rem;
}
@media(min-width:768px) {
    .inv2-main-grid { grid-template-columns:1fr 1fr; }
}

/* ── 2-column bottom grid ───────────────────────────────────────────────── */
.inv2-bottom-grid {
    display:grid;
    grid-template-columns:1fr;
    gap:1.25rem;
}
@media(min-width:768px) {
    .inv2-bottom-grid { grid-template-columns:1fr 1fr; }
}

/* ── Cards ──────────────────────────────────────────────────────────────── */
.inv2-card {
    background:#fff;
    border:1px solid #e5e7eb;
    border-radius:.875rem;
    box-shadow:0 1px 4px rgba(0,0,0,.07);
    overflow:hidden;
    display:flex;
    flex-direction:column;
}
.inv2-card-head {
    display:flex; align-items:center; gap:.5rem;
    padding:.7rem 1.25rem;
    border-bottom:1px solid #e5e7eb;
    font-size:.8rem; font-weight:700;
    text-transform:uppercase; letter-spacing:.05em;
}
.inv2-card-body { padding:1rem 1.25rem; }

/* ── AI card header — purple gradient ───────────────────────────────────── */
.inv2-ai-head {
    background:linear-gradient(120deg,#4f46e5 0%,#7c3aed 100%);
    color:#fff;
    padding:.875rem 1.25rem;
    display:flex; align-items:center; gap:.625rem;
}
.inv2-ai-head-title { font-size:.875rem; font-weight:700; letter-spacing:.03em; flex:1; }
.inv2-ai-head-sub   { font-size:.725rem; opacity:.85; margin-top:.125rem; }
.inv2-ai-head-badge {
    background:rgba(255,255,255,.2);
    border:1px solid rgba(255,255,255,.35);
    color:#fff;
    padding:.15rem .55rem;
    border-radius:9999px;
    font-size:.7rem; font-weight:600;
}
.inv2-ai-sparkle { font-size:1.05rem; line-height:1; }

/* ── AI accordion steps ─────────────────────────────────────────────────── */
.inv2-steps { padding:.625rem 0; }
.inv2-step { border-bottom:1px solid #f3f4f6; }
.inv2-step:last-child { border-bottom:none; }
details.inv2-step summary { list-style:none; cursor:pointer; }
details.inv2-step summary::-webkit-details-marker { display:none; }
.inv2-step-summary {
    display:flex; align-items:flex-start; gap:.75rem;
    padding:.7rem 1.25rem;
    user-select:none;
}
.inv2-step-summary:hover { background:#faf5ff; }
.inv2-step-num {
    flex-shrink:0;
    width:1.5rem; height:1.5rem;
    border-radius:50%;
    background:#ede9fe;
    color:#6d28d9;
    font-size:.7rem; font-weight:800;
    display:flex; align-items:center; justify-content:center;
    margin-top:.1rem;
}
.inv2-step-q {
    flex:1; font-size:.8125rem; font-weight:600; color:#374151; line-height:1.45;
}
.inv2-step-chevron {
    flex-shrink:0; width:1rem; height:1rem;
    color:#9ca3af; margin-top:.15rem;
    transition:transform .2s;
}
details[open].inv2-step .inv2-step-chevron { transform:rotate(90deg); }
.inv2-step-body {
    padding:.125rem 1.25rem .875rem 3.5rem;
    font-size:.8125rem; color:#374151; line-height:1.65;
}
.inv2-ai-footer {
    padding:.75rem 1.25rem;
    border-top:1px solid #ede9fe;
    font-size:.725rem; color:#9ca3af;
    background:#faf5ff;
    display:flex; align-items:center; gap:.4rem;
}

/* ── Detection data table ───────────────────────────────────────────────── */
.inv2-detect-head {
    background:#f8fafc;
    padding:.7rem 1.25rem;
    display:flex; align-items:center; gap:.5rem;
    border-bottom:1px solid #e5e7eb;
    font-size:.8rem; font-weight:700;
    text-transform:uppercase; letter-spacing:.05em; color:#374151;
}
.inv2-detect-table { width:100%; border-collapse:collapse; }
.inv2-detect-table th {
    font-size:.7rem; font-weight:700; text-transform:uppercase;
    letter-spacing:.05em; color:#6b7280;
    padding:.55rem 1.25rem;
    border-bottom:1px solid #e5e7eb;
    text-align:left;
    background:#f9fafb;
}
.inv2-detect-table td {
    padding:.55rem 1.25rem;
    font-size:.8125rem; color:#374151;
    border-bottom:1px solid #f3f4f6;
    line-height:1.4;
}
.inv2-detect-table tr:last-child td { border-bottom:none; }
.inv2-detect-table tbody tr:nth-child(even) td { background:#fafafa; }
.inv2-detect-table td:last-child { font-weight:600; color:#111827; text-align:right; }

/* ── Action Taken card ──────────────────────────────────────────────────── */
.inv2-action-head {
    background:linear-gradient(120deg,#15803d 0%,#16a34a 100%);
    color:#fff;
    padding:.7rem 1.25rem;
    display:flex; align-items:center; gap:.5rem;
    font-size:.8rem; font-weight:700;
    text-transform:uppercase; letter-spacing:.05em;
}
.inv2-action-row {
    display:flex; gap:.75rem;
    padding:.7rem 1.25rem;
    border-bottom:1px solid #f3f4f6;
    align-items:flex-start;
}
.inv2-action-row:last-child { border-bottom:none; }
.inv2-action-label { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#9ca3af; min-width:110px; flex-shrink:0; margin-top:.1rem; }
.inv2-action-value { font-size:.8125rem; color:#374151; line-height:1.5; }

/* ── Resolution card ────────────────────────────────────────────────────── */
.inv2-res-head {
    background:linear-gradient(120deg,#3730a3 0%,#4f46e5 100%);
    color:#fff;
    padding:.7rem 1.25rem;
    display:flex; align-items:center; gap:.5rem;
    font-size:.8rem; font-weight:700;
    text-transform:uppercase; letter-spacing:.05em;
}
.inv2-empty { padding:1.5rem 1.25rem; font-size:.8125rem; color:#9ca3af; text-align:center; }

/* ── Dark mode ──────────────────────────────────────────────────────────── */
.dark .inv2-infobar         { background:#1f2937; border-color:#374151; }
.dark .inv2-infobar-divider { background:#374151; }
.dark .inv2-infobar-desc    { color:#d1d5db; }
.dark .inv2-infobar-right-label { color:#6b7280; }
.dark .inv2-infobar-right-value { color:#e5e7eb; }
.dark .inv2-badge-group-label   { color:#6b7280; }
.dark .inv2-card            { background:#1f2937; border-color:#374151; }
.dark .inv2-card-head       { background:#111827; border-color:#374151; color:#d1d5db; }
.dark .inv2-step-summary:hover  { background:#2e1065; }
.dark .inv2-step-q          { color:#e5e7eb; }
.dark .inv2-step            { border-color:#374151; }
.dark .inv2-step-body       { color:#d1d5db; }
.dark .inv2-step-num        { background:#3b0764; color:#c4b5fd; }
.dark .inv2-ai-footer       { background:#1e1b4b; border-color:#3730a3; color:#818cf8; }
.dark .inv2-detect-head     { background:#111827; border-color:#374151; color:#d1d5db; }
.dark .inv2-detect-table th { background:#111827; color:#9ca3af; border-color:#374151; }
.dark .inv2-detect-table td { color:#d1d5db; border-color:#374151; }
.dark .inv2-detect-table tbody tr:nth-child(even) td { background:#111827; }
.dark .inv2-detect-table td:last-child { color:#f9fafb; }
.dark .inv2-action-row      { border-color:#374151; }
.dark .inv2-action-label    { color:#6b7280; }
.dark .inv2-action-value    { color:#d1d5db; }
.dark .inv2-empty           { color:#6b7280; }
.dark .inv2-badge-danger    { background:#450a0a; color:#f87171; }
.dark .inv2-badge-warning   { background:#451a03; color:#fbbf24; }
.dark .inv2-badge-info      { background:#172554; color:#93c5fd; }
.dark .inv2-badge-success   { background:#052e16; color:#4ade80; }
.dark .inv2-badge-gray      { background:#374151; color:#d1d5db; }
.dark .inv2-badge-purple    { background:#2e1065; color:#c4b5fd; }
</style>

@php
/* ── Helper: severity sort ─────────────────────────────────────────────── */
$sevOrder = ['critical'=>4,'high'=>3,'medium'=>2,'low'=>1];

/* ── Primary anomaly (highest severity) ───────────────────────────────── */
$primaryAnomaly = $record->anomalies
    ->sortByDesc(fn($a) => $sevOrder[$a->severity] ?? 0)
    ->first();

$topSeverity   = $primaryAnomaly?->severity ?? null;
$sevBadgeClass = match($topSeverity) {
    'critical' => 'inv2-badge-danger',
    'high'     => 'inv2-badge-warning',
    'medium'   => 'inv2-badge-info',
    default    => 'inv2-badge-gray',
};
$sevLabel = $topSeverity ? strtoupper($topSeverity) : 'N/A';

$ruleLabel = $primaryAnomaly?->getRuleLabel() ?? '—';

/* ── AI confidence → status label ─────────────────────────────────────── */
$aiStatusLabel = match($record->ai_confidence ?? null) {
    'established' => 'CAUSE ESTABLISHED',
    'probable'    => 'CAUSE PROBABLE',
    'suspected'   => 'UNDER INVESTIGATION',
    default       => 'PENDING ANALYSIS',
};
$aiStatusBadge = match($record->ai_confidence ?? null) {
    'established' => 'inv2-badge-success',
    'probable'    => 'inv2-badge-info',
    'suspected'   => 'inv2-badge-warning',
    default       => 'inv2-badge-gray',
};

/* ── Step 2: supporting evidence summary ──────────────────────────────── */
$supportingEvidence = $record->evidence->where('direction','supports');
$evidenceText = $supportingEvidence->count()
    ? $supportingEvidence->map(fn($e) => $e->label.': '.$e->getFormattedValue())->implode(', ')
    : 'No supporting evidence collected yet.';

/* ── Step 3: contributing factors ─────────────────────────────────────── */
$neutralEvidence  = $record->evidence->where('direction','context');
$anomalyDescs     = $record->anomalies->pluck('description')->filter()->values();
$contribFactors   = $anomalyDescs->merge($neutralEvidence->pluck('label'))->unique()->take(5);
$contribText      = $contribFactors->count()
    ? $contribFactors->implode('; ')
    : 'Multiple correlated signals detected across the investigation window.';

/* ── Step 4: business impact ──────────────────────────────────────────── */
$impactText = $record->revenue_at_risk
    ? 'Estimated revenue at risk: $'.number_format($record->revenue_at_risk, 2).'.'
    : 'Revenue impact not yet quantified.';

/* ── Step 6: long-term fix ────────────────────────────────────────────── */
$ltFix = $record->root_cause_notes
    ?? $record->outcome?->confirmed_root_cause
    ?? 'No long-term remediation notes recorded. Once the root cause is confirmed, document the systemic fix here.';

/* ── Step 7: KPIs derived from rule types ─────────────────────────────── */
$ruleKpiMap = [
    'sales_velocity_drop'  => ['Daily Sales Velocity','7-Day Rolling Average','Week-on-Week Revenue'],
    'stockout_risk'        => ['On-Hand Inventory Level','Days of Cover','Replenishment Lead Time'],
    'overstock_risk'       => ['Inventory Turnover Rate','Weeks of Supply','Sell-Through Rate'],
    'po_delay'             => ['PO Fill Rate','Delivery Lead Time','Supplier On-Time %'],
    'return_spike'         => ['Return Rate','Net Sales Volume','Customer Satisfaction'],
    'default'              => ['Revenue at Risk','Anomaly Recurrence Rate','Resolution Time (hrs)'],
];
$ruleType = $primaryAnomaly?->rule_type ?? 'default';
$kpiList  = $ruleKpiMap[$ruleType] ?? $ruleKpiMap['default'];

/* ── Detection data: evidence items or primary anomaly context ─────────── */
$detectionRows = [];
if ($record->evidence->count()) {
    foreach ($record->evidence->take(10) as $ev) {
        $detectionRows[] = ['label' => $ev->label, 'value' => $ev->getFormattedValue()];
    }
} elseif ($primaryAnomaly && is_array($primaryAnomaly->context)) {
    foreach ($primaryAnomaly->context as $k => $v) {
        $detectionRows[] = ['label' => ucwords(str_replace('_',' ',$k)), 'value' => is_array($v) ? json_encode($v) : $v];
    }
}

/* ── Action Taken ──────────────────────────────────────────────────────── */
$completedAction = $record->actions->where('status','completed')->last()
    ?? $record->actions->last();

/* ── Resolved by ───────────────────────────────────────────────────────── */
$resolvedByName = $record->assignedUser?->name ?? $record->assignedTeam?->name ?? null;
@endphp

{{-- ════════════════════════════════════════════════════════════════════════
     INFO BAR
     ════════════════════════════════════════════════════════════════════════ --}}
<div class="inv2-infobar">

    {{-- Left: badges + description --}}
    <div class="inv2-infobar-left">

        {{-- Badge groups row --}}
        <div style="display:flex;flex-wrap:wrap;gap:1.25rem;align-items:flex-end">
            <div class="inv2-badge-group">
                <div class="inv2-badge-group-label">Severity</div>
                <span class="inv2-badge {{ $sevBadgeClass }}">{{ $sevLabel }}</span>
            </div>
            <div class="inv2-badge-group">
                <div class="inv2-badge-group-label">Rule</div>
                <span class="inv2-badge inv2-badge-purple">{{ $ruleLabel }}</span>
            </div>
            @if($record->primary_sku)
            <div class="inv2-badge-group">
                <div class="inv2-badge-group-label">SKU</div>
                <span class="inv2-badge inv2-badge-gray" style="font-family:monospace;font-size:.75rem">{{ $record->primary_sku }}</span>
            </div>
            @endif
            <div class="inv2-badge-group">
                <div class="inv2-badge-group-label">Status</div>
                <span class="inv2-badge {{ $aiStatusBadge }}">{{ $aiStatusLabel }}</span>
            </div>
        </div>

        <div class="inv2-infobar-divider"></div>

        @if($record->description)
        <div>
            <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#9ca3af;margin-bottom:.375rem">Description</div>
            <p class="inv2-infobar-desc">{{ $record->description }}</p>
        </div>
        @endif

    </div>

    {{-- Right: metadata grid --}}
    <div class="inv2-infobar-right">
        <div class="inv2-infobar-right-row">
            <span class="inv2-infobar-right-label">Detected At</span>
            <span class="inv2-infobar-right-value">{{ $record->opened_at?->format('M j, Y g:i A') ?? '—' }}</span>
        </div>
        <div class="inv2-infobar-right-row">
            <span class="inv2-infobar-right-label">Investigated At</span>
            <span class="inv2-infobar-right-value">{{ $record->ai_generated_at?->format('M j, Y g:i A') ?? '—' }}</span>
        </div>
        <div class="inv2-infobar-divider"></div>
        <div class="inv2-infobar-right-row">
            <span class="inv2-infobar-right-label">Tenant</span>
            <span class="inv2-infobar-right-value">{{ $record->tenant?->name ?? '—' }}</span>
        </div>
        @if($record->primaryStore)
        <div class="inv2-infobar-right-row">
            <span class="inv2-infobar-right-label">Store</span>
            <span class="inv2-infobar-right-value">{{ $record->primaryStore->name }}</span>
        </div>
        @endif
        @if($record->assignedTeam)
        <div class="inv2-infobar-right-row">
            <span class="inv2-infobar-right-label">Team</span>
            <span class="inv2-infobar-right-value">{{ $record->assignedTeam->name }}</span>
        </div>
        @endif
        @if($record->anomaly_count > 0)
        <div class="inv2-infobar-right-row">
            <span class="inv2-infobar-right-label">Anomalies</span>
            <span class="inv2-infobar-right-value">{{ $record->anomaly_count }}</span>
        </div>
        @endif
    </div>

</div>

{{-- ════════════════════════════════════════════════════════════════════════
     MAIN GRID: AI Investigation (left) + Detection Data (right)
     ════════════════════════════════════════════════════════════════════════ --}}
<div class="inv2-main-grid">

    {{-- ── LEFT: AI 7-Step Investigation ─────────────────────────────────── --}}
    <div class="inv2-card">
        {{-- Purple gradient header --}}
        <div class="inv2-ai-head">
            <span class="inv2-ai-sparkle">✦</span>
            <div style="flex:1">
                <div class="inv2-ai-head-title">AI Investigation</div>
                <div class="inv2-ai-head-sub">7-Step Root Cause Analysis</div>
            </div>
            @if($record->ai_confidence)
            <span class="inv2-ai-head-badge">{{ ucfirst($record->ai_confidence) }}</span>
            @endif
        </div>

        {{-- Steps accordion --}}
        <div class="inv2-steps">

            {{-- Step 1: Root Cause --}}
            <details class="inv2-step"{{ $record->ai_root_cause ? ' open' : '' }}>
                <summary class="inv2-step-summary">
                    <span class="inv2-step-num">1</span>
                    <span class="inv2-step-q">What is most likely causing this issue?</span>
                    <svg class="inv2-step-chevron" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
                </summary>
                <div class="inv2-step-body">
                    {{ $record->ai_root_cause ?? 'Root cause analysis not yet generated. Click "Generate Narrative" to trigger the AI investigation.' }}
                </div>
            </details>

            {{-- Step 2: Evidence --}}
            <details class="inv2-step">
                <summary class="inv2-step-summary">
                    <span class="inv2-step-num">2</span>
                    <span class="inv2-step-q">What evidence exists to support this conclusion?</span>
                    <svg class="inv2-step-chevron" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
                </summary>
                <div class="inv2-step-body">{{ $evidenceText }}</div>
            </details>

            {{-- Step 3: Contributing Factors --}}
            <details class="inv2-step">
                <summary class="inv2-step-summary">
                    <span class="inv2-step-num">3</span>
                    <span class="inv2-step-q">What contributing factors may have amplified this?</span>
                    <svg class="inv2-step-chevron" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
                </summary>
                <div class="inv2-step-body">{{ $contribText }}</div>
            </details>

            {{-- Step 4: Business Impact --}}
            <details class="inv2-step">
                <summary class="inv2-step-summary">
                    <span class="inv2-step-num">4</span>
                    <span class="inv2-step-q">What is the business impact if left unresolved?</span>
                    <svg class="inv2-step-chevron" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
                </summary>
                <div class="inv2-step-body">{{ $impactText }}</div>
            </details>

            {{-- Step 5: Immediate Action --}}
            <details class="inv2-step">
                <summary class="inv2-step-summary">
                    <span class="inv2-step-num">5</span>
                    <span class="inv2-step-q">What immediate action should be taken?</span>
                    <svg class="inv2-step-chevron" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
                </summary>
                <div class="inv2-step-body">
                    {{ $record->ai_recommended_action ?? 'No recommended action generated yet.' }}
                </div>
            </details>

            {{-- Step 6: Long-term Fix --}}
            <details class="inv2-step">
                <summary class="inv2-step-summary">
                    <span class="inv2-step-num">6</span>
                    <span class="inv2-step-q">What is the long-term systemic fix?</span>
                    <svg class="inv2-step-chevron" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
                </summary>
                <div class="inv2-step-body">{{ $ltFix }}</div>
            </details>

            {{-- Step 7: KPIs to Monitor --}}
            <details class="inv2-step">
                <summary class="inv2-step-summary">
                    <span class="inv2-step-num">7</span>
                    <span class="inv2-step-q">Which KPIs should be monitored going forward?</span>
                    <svg class="inv2-step-chevron" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
                </summary>
                <div class="inv2-step-body">
                    <ul style="margin:0;padding-left:1.25rem;display:flex;flex-direction:column;gap:.35rem">
                        @foreach($kpiList as $kpi)
                        <li>{{ $kpi }}</li>
                        @endforeach
                    </ul>
                </div>
            </details>

        </div>

        {{-- Footer --}}
        <div class="inv2-ai-footer">
            <span>✦</span>
            <span>AI Model: Claude Haiku</span>
            @if($record->ai_generated_at)
            <span style="margin-left:auto">Generated {{ $record->ai_generated_at->diffForHumans() }}</span>
            @endif
        </div>
    </div>

    {{-- ── RIGHT: Detection Data (Raw Context) ──────────────────────────── --}}
    <div class="inv2-card">
        <div class="inv2-detect-head">
            <svg style="width:1rem;height:1rem;color:#6b7280" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
            </svg>
            Detection Data (Raw Context)
        </div>

        @if(count($detectionRows))
        <table class="inv2-detect-table">
            <thead>
                <tr>
                    <th>Field</th>
                    <th style="text-align:right">Value</th>
                </tr>
            </thead>
            <tbody>
                @foreach($detectionRows as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td>{{ $row['value'] }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @else
        <div class="inv2-empty">No detection data available yet.</div>
        @endif
    </div>

</div>

{{-- ════════════════════════════════════════════════════════════════════════
     BOTTOM GRID: Action Taken (left) + Resolution (right)
     ════════════════════════════════════════════════════════════════════════ --}}
<div class="inv2-bottom-grid">

    {{-- ── Action Taken ────────────────────────────────────────────────── --}}
    <div class="inv2-card">
        <div class="inv2-action-head">
            <svg style="width:1rem;height:1rem" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            Action Taken
        </div>

        @if($completedAction)
            <div class="inv2-action-row">
                <span class="inv2-action-label">Action</span>
                <span class="inv2-action-value" style="font-weight:600;color:#111827">{{ $completedAction->title }}</span>
            </div>
            @if($completedAction->description)
            <div class="inv2-action-row">
                <span class="inv2-action-label">Notes</span>
                <span class="inv2-action-value">{{ $completedAction->description }}</span>
            </div>
            @endif
            <div class="inv2-action-row">
                <span class="inv2-action-label">Action Taken At</span>
                <span class="inv2-action-value">{{ $completedAction->updated_at?->format('M j, Y g:i A') ?? '—' }}</span>
            </div>
            @if($completedAction->assignedTo)
            <div class="inv2-action-row">
                <span class="inv2-action-label">Taken By</span>
                <span class="inv2-action-value">
                    {{ $completedAction->assignedTo->name }}
                    @if($completedAction->assignedTo->is_super_admin ?? false)<span style="color:#9ca3af"> (Admin)</span>@endif
                </span>
            </div>
            @endif
            <div class="inv2-action-row">
                <span class="inv2-action-label">Status</span>
                <span class="inv2-action-value">
                    @php
                    $aBadge = match($completedAction->status) {
                        'completed'   => 'inv2-badge-success',
                        'in_progress' => 'inv2-badge-info',
                        'cancelled'   => 'inv2-badge-gray',
                        default       => 'inv2-badge-warning',
                    };
                    @endphp
                    <span class="inv2-badge {{ $aBadge }}">{{ ucwords(str_replace('_',' ',$completedAction->status)) }}</span>
                </span>
            </div>
        @else
            <div class="inv2-empty">No actions recorded yet. Use <strong>Add Action</strong> above.</div>
        @endif
    </div>

    {{-- ── Resolution ──────────────────────────────────────────────────── --}}
    <div class="inv2-card">
        <div class="inv2-res-head">
            <svg style="width:1rem;height:1rem" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
            </svg>
            Resolution
        </div>

        @if($record->resolution_notes || $record->resolved_at || $record->outcome)
            @if($record->resolution_notes)
            <div class="inv2-action-row">
                <span class="inv2-action-label">Notes</span>
                <span class="inv2-action-value">{{ $record->resolution_notes }}</span>
            </div>
            @endif

            <div class="inv2-action-row">
                <span class="inv2-action-label">Status</span>
                <span class="inv2-action-value">
                    @php
                    $rBadge = match($record->status) {
                        'resolved' => 'inv2-badge-success',
                        'closed'   => 'inv2-badge-gray',
                        default    => 'inv2-badge-warning',
                    };
                    @endphp
                    <span class="inv2-badge {{ $rBadge }}">{{ strtoupper(str_replace('_',' ',$record->status)) }}</span>
                </span>
            </div>

            @if($record->resolved_at)
            <div class="inv2-action-row">
                <span class="inv2-action-label">Resolved At</span>
                <span class="inv2-action-value">{{ $record->resolved_at->format('M j, Y g:i A') }}</span>
            </div>
            @endif

            @if($resolvedByName)
            <div class="inv2-action-row">
                <span class="inv2-action-label">Resolved By</span>
                <span class="inv2-action-value">{{ $resolvedByName }}</span>
            </div>
            @endif

            @if($record->outcome)
            @php $outcome = $record->outcome; @endphp
            <div class="inv2-action-row" style="background:#f0fdf4;flex-wrap:wrap;gap:.5rem">
                <span class="inv2-action-label">Financial</span>
                <span class="inv2-action-value" style="display:flex;gap:1rem;flex-wrap:wrap">
                    @if($outcome->revenue_at_risk)
                    <span style="color:#dc2626">At Risk: ${{ number_format($outcome->revenue_at_risk,0) }}</span>
                    @endif
                    @if($outcome->observed_recovery)
                    <span style="color:#16a34a">Recovered: ${{ number_format($outcome->observed_recovery,0) }}</span>
                    @endif
                    @if($outcome->getRecoveryRate() !== null)
                    <span style="color:#2563eb">Rate: {{ $outcome->getRecoveryRate() }}%</span>
                    @endif
                </span>
            </div>
            @endif
        @else
            <div class="inv2-empty">Investigation not yet resolved.</div>
        @endif
    </div>

</div>

{{-- ═══ M23 — Data-health caveats + Watch/Snooze status + Collaboration ═══ --}}
<style>
    .col-wrap { display:flex; flex-direction:column; gap:1.25rem; margin-top:1.25rem; }
    .col-caveat { background:#fffbeb; border:1px solid #fde68a; border-radius:.75rem; padding:.9rem 1.15rem; color:#92400e; font-size:.82rem; }
    .col-caveat strong { display:block; margin-bottom:.3rem; }
    .col-status-row { display:flex; flex-wrap:wrap; gap:.75rem; }
    .col-chip { display:inline-flex; align-items:center; gap:.4rem; padding:.35rem .75rem; border-radius:9999px; font-size:.75rem; font-weight:600; }
    .col-chip.watch { background:#dbeafe; color:#1e40af; }
    .col-chip.snooze { background:#fef3c7; color:#92400e; }
    .col-card { background:#fff; border:1px solid #e5e7eb; border-radius:.875rem; box-shadow:0 1px 4px rgba(0,0,0,.07); overflow:hidden; }
    .col-card-head { display:flex; align-items:center; gap:.5rem; padding:.75rem 1.25rem; border-bottom:1px solid #f3f4f6; font-weight:700; font-size:.9rem; color:#111827; }
    .col-card-body { padding:1rem 1.25rem; display:flex; flex-direction:column; gap:.9rem; }
    .col-comment { display:flex; flex-direction:column; gap:.25rem; padding-bottom:.85rem; border-bottom:1px dashed #f3f4f6; }
    .col-comment:last-child { border-bottom:none; padding-bottom:0; }
    .col-comment-meta { font-size:.72rem; color:#9ca3af; }
    .col-comment-author { font-weight:700; color:#374151; }
    .col-comment-body { font-size:.85rem; color:#1f2937; line-height:1.55; white-space:pre-wrap; }
    .col-empty { color:#9ca3af; font-size:.82rem; }
    .dark .col-card { background:#1f2937; border-color:#374151; }
    .dark .col-card-head { background:#111827; border-color:#374151; color:#d1d5db; }
</style>

<div class="col-wrap">

    @php $caveats = $this->dataHealthCaveats(); @endphp
    @if(!empty($caveats))
    <div class="col-caveat">
        <strong>Data-health limitations may affect this investigation's evidence:</strong>
        <ul style="margin:0;padding-left:1.1rem;">
            @foreach($caveats as $caveat)
                <li>{{ $caveat }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    @php $activeWatches = $record->watches->where('active', true); @endphp
    @if($record->isSnoozed() || $activeWatches->count())
    <div class="col-status-row">
        @if($record->isSnoozed())
            <span class="col-chip snooze">
                Snoozed until {{ $record->snoozed_until->format('M j, Y') }}
                @if($record->snooze_reason)· {{ \App\Models\Suppression::REASON_LABELS[$record->snooze_reason] ?? $record->snooze_reason }}@endif
            </span>
        @endif
        @if($activeWatches->count())
            <span class="col-chip watch">
                {{ $activeWatches->count() }} watcher(s): {{ $activeWatches->map(fn($w) => $w->getWatcherLabel())->take(3)->implode(', ') }}
            </span>
        @endif
    </div>
    @endif

    {{-- Collaboration --}}
    <div class="col-card">
        <div class="col-card-head">
            Collaboration
            <span style="margin-left:auto;font-weight:500;color:#9ca3af;font-size:.75rem;">{{ $record->comments->count() }} comment(s)</span>
        </div>
        <div class="col-card-body">
            @php $comments = $record->comments->whereNull('parent_id'); @endphp
            @forelse($comments as $comment)
                <div class="col-comment">
                    <div class="col-comment-meta">
                        <span class="col-comment-author">{{ $comment->getAuthorLabel() }}</span>
                        · {{ $comment->created_at->diffForHumans() }}
                        @if($comment->wasEdited()) · edited @endif
                    </div>
                    <div class="col-comment-body">{{ $comment->body }}</div>
                </div>
            @empty
                <div class="col-empty">No comments yet. Use the “Comment” button above to add context — mentions notify teammates.</div>
            @endforelse
        </div>
    </div>

</div>

</x-filament-panels::page>
