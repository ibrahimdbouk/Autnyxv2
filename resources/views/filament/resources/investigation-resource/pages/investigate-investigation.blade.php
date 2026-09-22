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
    background:var(--ax-bg);
    border:1px solid var(--ax-line);
    border-radius:.875rem;
    box-shadow:var(--ax-shadow);
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
.inv2-badge-group-label { font-size:.65rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--ax-faint); }
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
.inv2-badge-danger   { background:var(--ax-danger-soft); color:var(--ax-danger-fg); }
.inv2-badge-warning  { background:var(--ax-warning-soft); color:var(--ax-warning-fg); }
.inv2-badge-info     { background:var(--ax-info-soft); color:var(--ax-info-fg); }
.inv2-badge-success  { background:var(--ax-success-soft); color:var(--ax-success-fg); }
.inv2-badge-gray     { background:var(--ax-neutral-soft); color:var(--ax-neutral-fg); }
.inv2-badge-purple   { background:var(--ax-accent-soft); color:var(--ax-accent-strong); }

/* ── Info bar description & divider ─────────────────────────────────────── */
.inv2-infobar-desc { font-size:.875rem; color:var(--ax-text); line-height:1.65; }
.inv2-infobar-divider { height:1px; background:var(--ax-line-2); margin:.5rem 0; }
.inv2-infobar-right-row { display:flex; justify-content:space-between; gap:.5rem; }
.inv2-infobar-right-label { font-size:.75rem; color:var(--ax-faint); font-weight:500; white-space:nowrap; }
.inv2-infobar-right-value { font-size:.8125rem; color:var(--ax-text); font-weight:600; text-align:right; }

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
    background:var(--ax-bg);
    border:1px solid var(--ax-line);
    border-radius:.875rem;
    box-shadow:var(--ax-shadow);
    overflow:hidden;
    display:flex;
    flex-direction:column;
}
.inv2-card-head {
    display:flex; align-items:center; gap:.5rem;
    padding:.7rem 1.25rem;
    border-bottom:1px solid var(--ax-line);
    background:var(--ax-panel);
    color:var(--ax-text);
    font-size:.8rem; font-weight:700;
    text-transform:uppercase; letter-spacing:.05em;
}
.inv2-card-body { padding:1rem 1.25rem; }

/* ── AI card header — violet gradient ───────────────────────────────────── */
.inv2-ai-head {
    background:linear-gradient(120deg,var(--ax-accent-800) 0%,var(--ax-accent-600) 100%);
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
.inv2-step { border-bottom:1px solid var(--ax-line-2); }
.inv2-step:last-child { border-bottom:none; }
details.inv2-step summary { list-style:none; cursor:pointer; }
details.inv2-step summary::-webkit-details-marker { display:none; }
.inv2-step-summary {
    display:flex; align-items:flex-start; gap:.75rem;
    padding:.7rem 1.25rem;
    user-select:none;
}
.inv2-step-summary:hover { background:var(--ax-accent-soft); }
.inv2-step-num {
    flex-shrink:0;
    width:1.5rem; height:1.5rem;
    border-radius:50%;
    background:var(--ax-accent-soft);
    color:var(--ax-accent-strong);
    font-size:.7rem; font-weight:800;
    display:flex; align-items:center; justify-content:center;
    margin-top:.1rem;
}
.inv2-step-q {
    flex:1; font-size:.8125rem; font-weight:600; color:var(--ax-text); line-height:1.45;
}
.inv2-step-chevron {
    flex-shrink:0; width:1rem; height:1rem;
    color:var(--ax-faint); margin-top:.15rem;
    transition:transform .2s;
}
details[open].inv2-step .inv2-step-chevron { transform:rotate(90deg); }
.inv2-step-body {
    padding:.125rem 1.25rem .875rem 3.5rem;
    font-size:.8125rem; color:var(--ax-text); line-height:1.65;
}
.inv2-ai-footer {
    padding:.75rem 1.25rem;
    border-top:1px solid var(--ax-accent-soft-border);
    font-size:.725rem; color:var(--ax-muted);
    background:var(--ax-accent-soft);
    display:flex; align-items:center; gap:.4rem;
}

/* ── Detection data table ───────────────────────────────────────────────── */
.inv2-detect-head {
    background:var(--ax-panel-2);
    padding:.7rem 1.25rem;
    display:flex; align-items:center; gap:.5rem;
    border-bottom:1px solid var(--ax-line);
    font-size:.8rem; font-weight:700;
    text-transform:uppercase; letter-spacing:.05em; color:var(--ax-text);
}
.inv2-detect-table { width:100%; border-collapse:collapse; }
.inv2-detect-table th {
    font-size:.7rem; font-weight:700; text-transform:uppercase;
    letter-spacing:.05em; color:var(--ax-muted);
    padding:.55rem 1.25rem;
    border-bottom:1px solid var(--ax-line);
    text-align:left;
    background:var(--ax-panel);
}
.inv2-detect-table td {
    padding:.55rem 1.25rem;
    font-size:.8125rem; color:var(--ax-text);
    border-bottom:1px solid var(--ax-line-2);
    line-height:1.4;
}
.inv2-detect-table tr:last-child td { border-bottom:none; }
.inv2-detect-table tbody tr:nth-child(even) td { background:var(--ax-panel); }
.inv2-detect-table td:last-child { font-weight:600; color:var(--ax-ink); text-align:right; }

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
    border-bottom:1px solid var(--ax-line-2);
    align-items:flex-start;
}
.inv2-action-row:last-child { border-bottom:none; }
.inv2-action-label { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--ax-faint); min-width:110px; flex-shrink:0; margin-top:.1rem; }
.inv2-action-value { font-size:.8125rem; color:var(--ax-text); line-height:1.5; }

/* ── Resolution card ────────────────────────────────────────────────────── */
.inv2-res-head {
    background:linear-gradient(120deg,var(--ax-accent-900) 0%,var(--ax-accent-700) 100%);
    color:#fff;
    padding:.7rem 1.25rem;
    display:flex; align-items:center; gap:.5rem;
    font-size:.8rem; font-weight:700;
    text-transform:uppercase; letter-spacing:.05em;
}
.inv2-empty { padding:1.5rem 1.25rem; font-size:.8125rem; color:var(--ax-faint); text-align:center; }
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

/* ── Evidence grouping helper ─────────────────────────────────────────────
   The evidence collector emits one row per store, so a chain-wide signal
   arrives as e.g. 15 identical "Current on-hand quantity" rows. Rendering
   those verbatim is the "long and unclear" problem. This collapses a group of
   same-label rows into one readable line: a numeric range (with a zero count)
   when the values are numbers, otherwise a short distinct-value list. ─────── */
$numify = function ($s) {
    $n = preg_replace('/[^\d.\-]/', '', (string) $s);
    return ($n !== '' && is_numeric($n)) ? (float) $n : null;
};
$trimNum = fn ($f) => rtrim(rtrim(number_format((float) $f, 2), '0'), '.');
$summariseValues = function ($items) use ($numify, $trimNum) {
    if ($items->count() === 1) {
        return $items->first()->getFormattedValue();
    }
    $nums = $items->map(fn ($e) => $numify($e->getFormattedValue()));
    if (! $nums->contains(null)) {
        $zeros = $nums->filter(fn ($v) => $v == 0.0)->count();
        $note  = $zeros ? ' (' . $zeros . ' at zero)' : '';
        return $items->count() . ' locations, ' . $trimNum($nums->min()) . '–' . $trimNum($nums->max()) . $note;
    }
    $distinct = $items->map(fn ($e) => $e->getFormattedValue())->unique()->values();
    return $distinct->take(6)->implode(', ') . ($distinct->count() > 6 ? ' +' . ($distinct->count() - 6) . ' more' : '');
};

/* ── Step 2: supporting evidence — grouped so identical labels don't repeat ─ */
$supportingEvidence = $record->evidence->where('direction','supports');
$evidenceText = $supportingEvidence->isEmpty()
    ? 'No supporting evidence collected yet.'
    : $supportingEvidence->groupBy('label')
        ->map(fn ($items, $label) => $label . ': ' . $summariseValues($items))
        ->implode('. ') . '.';

/* ── Step 3: contributing factors — de-duplicate near-identical lines ────── */
$neutralEvidence = $record->evidence->where('direction','context');
$rawFactors = $record->anomalies->pluck('description')->filter()
    ->merge($neutralEvidence->pluck('label'))->filter();
$factorGroups = [];
foreach ($rawFactors as $f) {
    $key = preg_replace('/\s+/', ' ', trim(preg_replace('/\d+|ST\d+|AED[\d.,]*/i', '', (string) $f)));
    if (! isset($factorGroups[$key])) {
        $factorGroups[$key] = ['sample' => $f, 'n' => 0];
    }
    $factorGroups[$key]['n']++;
}
$contribText = count($factorGroups)
    ? collect($factorGroups)->take(4)
        ->map(fn ($g) => $g['n'] > 1 ? $g['sample'] . ' (and ' . ($g['n'] - 1) . ' similar)' : $g['sample'])
        ->implode('; ')
    : 'Multiple correlated signals detected across the investigation window.';

/* ── Step 4: business impact (tenant currency, not a hardcoded $) ────────── */
$curr = \App\Support\Money::symbol(\Filament\Facades\Filament::getTenant()?->currencyCode());
$impactText = $record->revenue_at_risk
    ? 'Estimated revenue at risk: ' . $curr . number_format($record->revenue_at_risk, 2) . '.'
    : 'Revenue impact not yet quantified.';

/* ── Step 6: long-term fix ────────────────────────────────────────────── */
$ltFix = $record->root_cause_notes
    ?? $record->outcome?->confirmed_root_cause
    ?? 'No long-term remediation notes recorded. Once the root cause is confirmed, document the systemic fix here.';

/* ── Narration quality: prefer AI-narrated fields over raw concatenation ──
   The narrator now writes plain-language evidence/factors/impact/headline;
   fall back to the deterministic text only when a field is empty. ───────── */
$aiHeadline     = trim((string) ($record->ai_headline ?? '')) ?: null;
$aiEvidenceList = is_array($record->ai_evidence)
    ? array_values(array_filter(array_map(fn ($e) => trim((string) $e), $record->ai_evidence), fn ($e) => $e !== '' && $e !== '—'))
    : [];
$aiContribList  = is_array($record->ai_contributing_factors)
    ? array_values(array_filter(array_map(fn ($f) => trim((string) $f), $record->ai_contributing_factors), fn ($f) => $f !== '' && $f !== '—'))
    : [];
if (trim((string) ($record->ai_business_impact ?? '')) !== '') { $impactText = $record->ai_business_impact; }
if (trim((string) ($record->ai_long_term_fix ?? '')) !== '')   { $ltFix = $record->ai_long_term_fix; }

/* ── Step 7: KPIs derived from the real rule types ────────────────────── */
$ruleKpiMap = [
    'sales_drop'         => ['Daily Sales Velocity','7-Day Rolling Average','Week-on-Week Revenue'],
    'sales_spike'        => ['Daily Sales Velocity','7-Day Rolling Average','Week-on-Week Revenue'],
    'stockout_risk'      => ['On-Hand Inventory Level','Days of Cover','Replenishment Lead Time'],
    'overstock'          => ['Inventory Turnover Rate','Weeks of Supply','Sell-Through Rate'],
    'phantom_inventory'  => ['On-Hand vs Demand','Weeks of Supply','Sell-Through Rate'],
    'po_overdue'         => ['PO Fill Rate','Delivery Lead Time','Supplier On-Time %'],
    'po_late_receipt'    => ['PO Fill Rate','Delivery Lead Time','Supplier On-Time %'],
    'return_rate_spike'  => ['Return Rate','Net Sales Volume','Sell-Through Rate'],
    'default'            => ['Revenue at Risk','Anomaly Recurrence Rate','Resolution Time (hrs)'],
];
$ruleType = $primaryAnomaly?->rule_type ?? 'default';
$kpiList  = $ruleKpiMap[$ruleType] ?? $ruleKpiMap['default'];

/* ── Detection data: grouped evidence, else primary anomaly context ─────── */
$detectionRows = [];
if ($record->evidence->count()) {
    foreach ($record->evidence->groupBy('label')->take(12) as $label => $items) {
        $detectionRows[] = ['label' => $label, 'value' => $summariseValues($items)];
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
            <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--ax-faint);margin-bottom:.375rem">Description</div>
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
     B8: Deterministic root-cause inference (retail causal graph)
     ════════════════════════════════════════════════════════════════════════ --}}
@php
    $causal = $this->causalInference();
    $causalTier = ['verified' => 'success', 'likely' => 'warning', 'correlated' => null];
@endphp
@if($causal)
    <x-ui.card variant="accent" class="ax-mb-5">
        <div class="ax-between ax-wrap">
            <span class="ax-section-kicker">Root cause · inferred (deterministic)</span>
            <x-ui.badge :color="$causalTier[$causal['tier']] ?? null">{{ $causal['tier'] }} · {{ $causal['confidence'] }}%</x-ui.badge>
        </div>
        <p class="ax-card-title ax-mt-2">{{ $causal['root_label'] }}</p>
        <div class="ax-chain ax-mt-2">
            @foreach($causal['chain'] as $i => $step)
                @if($i > 0)<span class="ax-chain-arrow">→</span>@endif
                <span class="ax-chain-node {{ $i === 0 ? 'ax-chain-node--root' : '' }}">{{ $step['label'] }}</span>
            @endforeach
        </div>
        <p class="ax-muted ax-text-sm ax-lh ax-mt-3">{{ $causal['explanation'] }}</p>
        @if($causal['alternatives'] > 0)
            <p class="ax-faint ax-text-xs ax-mt-2">{{ $causal['alternatives'] }} other independent signal chain(s) present — see the evidence panel.</p>
        @endif
        <p class="ax-faint ax-text-xs ax-mt-2">Inferred deterministically from the retail causal graph — the AI narrative below phrases this conclusion, it does not choose it.</p>
    </x-ui.card>
@endif

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

        {{-- Plain-language headline — the 5-second read, above the accordion --}}
        @if($aiHeadline)
        <div style="padding:.9rem 1rem;font-size:1.02rem;font-weight:600;line-height:1.45;border-bottom:1px solid rgba(0,0,0,.06)">
            {{ $aiHeadline }}
        </div>
        @endif

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
                <div class="inv2-step-body">
                    @if(count($aiEvidenceList))
                        <ul style="margin:0;padding-left:1.1rem">
                            @foreach($aiEvidenceList as $e)<li style="margin:.15rem 0">{{ $e }}</li>@endforeach
                        </ul>
                    @else
                        {{ $evidenceText }}
                    @endif
                </div>
            </details>

            {{-- Step 3: Contributing Factors --}}
            <details class="inv2-step">
                <summary class="inv2-step-summary">
                    <span class="inv2-step-num">3</span>
                    <span class="inv2-step-q">What contributing factors may have amplified this?</span>
                    <svg class="inv2-step-chevron" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
                </summary>
                <div class="inv2-step-body">
                    @if(count($aiContribList))
                        <ul style="margin:0;padding-left:1.1rem">
                            @foreach($aiContribList as $f)<li style="margin:.15rem 0">{{ $f }}</li>@endforeach
                        </ul>
                    @else
                        {{ $contribText }}
                    @endif
                </div>
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
            <svg style="width:1rem;height:1rem;color:var(--ax-muted)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
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
                <span class="inv2-action-value" style="font-weight:600;color:var(--ax-ink)">{{ $completedAction->title }}</span>
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
                    @if($completedAction->assignedTo->is_super_admin ?? false)<span style="color:var(--ax-faint)"> (Admin)</span>@endif
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
            <div class="inv2-action-row" style="background:var(--ax-success-soft);flex-wrap:wrap;gap:.5rem">
                <span class="inv2-action-label">Financial</span>
                <span class="inv2-action-value" style="display:flex;gap:1rem;flex-wrap:wrap">
                    @if($outcome->revenue_at_risk)
                    <span style="color:var(--ax-danger)">At Risk: ${{ number_format($outcome->revenue_at_risk,0) }}</span>
                    @endif
                    @if($outcome->observed_recovery)
                    <span style="color:var(--ax-success)">Recovered: ${{ number_format($outcome->observed_recovery,0) }}</span>
                    @endif
                    @if($outcome->getRecoveryRate() !== null)
                    <span style="color:var(--ax-info)">Rate: {{ $outcome->getRecoveryRate() }}%</span>
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
    .col-caveat { background:var(--ax-warning-soft); border:1px solid var(--ax-warning-soft); border-radius:.75rem; padding:.9rem 1.15rem; color:var(--ax-warning-fg); font-size:.82rem; }
    .col-caveat strong { display:block; margin-bottom:.3rem; }
    .col-status-row { display:flex; flex-wrap:wrap; gap:.75rem; }
    .col-chip { display:inline-flex; align-items:center; gap:.4rem; padding:.35rem .75rem; border-radius:9999px; font-size:.75rem; font-weight:600; }
    .col-chip.watch { background:var(--ax-info-soft); color:var(--ax-info-fg); }
    .col-chip.snooze { background:var(--ax-warning-soft); color:var(--ax-warning-fg); }
    .col-card { background:var(--ax-bg); border:1px solid var(--ax-line); border-radius:.875rem; box-shadow:var(--ax-shadow); overflow:hidden; }
    .col-card-head { display:flex; align-items:center; gap:.5rem; padding:.75rem 1.25rem; border-bottom:1px solid var(--ax-line-2); background:var(--ax-panel); font-weight:700; font-size:.9rem; color:var(--ax-ink); }
    .col-card-body { padding:1rem 1.25rem; display:flex; flex-direction:column; gap:.9rem; }
    .col-comment { display:flex; flex-direction:column; gap:.25rem; padding-bottom:.85rem; border-bottom:1px dashed var(--ax-line-2); }
    .col-comment:last-child { border-bottom:none; padding-bottom:0; }
    .col-comment-meta { font-size:.72rem; color:var(--ax-faint); }
    .col-comment-author { font-weight:700; color:var(--ax-text); }
    .col-comment-body { font-size:.85rem; color:var(--ax-ink); line-height:1.55; white-space:pre-wrap; }
    .col-empty { color:var(--ax-faint); font-size:.82rem; }
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
            <span style="margin-left:auto;font-weight:500;color:var(--ax-faint);font-size:.75rem;">{{ $record->comments->count() }} comment(s)</span>
        </div>
        <div class="col-card-body">
            @php $comments = $record->comments->whereNull('parent_id'); @endphp
            @forelse($comments as $comment)
                <div class="col-comment">
                    <div class="col-comment-meta">
                        <span class="col-comment-author">{{ $comment->getAuthorLabel() }}</span>
                        · {{ $comment->created_at->diffForHumans() }}
                        @if($comment->isFromEmail()) · <span style="color:var(--ax-warning-fg);">via email</span> @endif
                        @if($comment->wasEdited()) · edited @endif
                    </div>
                    <div class="col-comment-body">{{ $comment->body }}</div>
                </div>
            @empty
                <div class="col-empty">No comments yet. Use the “Comment” button above to add context — mentions notify teammates.</div>
            @endforelse
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════════════
         DEEP INVESTIGATION — optional, interactive drill-down. Appends BELOW the
         canonical 7-question view above and changes nothing about it. Every value
         is read from governed data (DeepInvestigationService); nothing is invented.
         ═══════════════════════════════════════════════════════════════════════ --}}
    @php($deep = $this->deepInvestigation())
    @if(!empty($deep))
    @php($cm = $deep['cause_map'] ?? [])
    @php($tr = $deep['trail'] ?? [])
    @php($cf = $deep['confidence'] ?? [])
    @php($ev = $deep['evidence'] ?? [])
    @php($imp = $deep['impact'] ?? [])
    <style>
    .dinv-wrap { margin-top:1.75rem; }
    .dinv-head { display:flex; align-items:center; gap:.6rem; margin-bottom:.9rem; }
    .dinv-head h2 { font-size:1.05rem; font-weight:800; color:var(--ax-ink,#111827); margin:0; letter-spacing:-.01em; }
    .dinv-head .dinv-kicker { font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:var(--ax-accent-strong,#7c3aed); background:var(--ax-accent-soft,#f5f3ff); padding:.2rem .55rem; border-radius:9999px; }
    .dinv-sub { font-size:.8rem; color:var(--ax-faint,#6b7280); margin:-.4rem 0 1rem; line-height:1.5; max-width:76ch; }
    .dinv-panel { border:1px solid var(--ax-line,#e5e7eb); border-radius:.85rem; background:var(--ax-bg,#fff); margin-bottom:.85rem; overflow:hidden; box-shadow:var(--ax-shadow,0 1px 2px rgba(0,0,0,.04)); }
    .dinv-panel > summary { list-style:none; cursor:pointer; display:flex; align-items:center; gap:.6rem; padding:.85rem 1.15rem; font-weight:700; font-size:.875rem; color:var(--ax-text,#1f2937); user-select:none; }
    .dinv-panel > summary::-webkit-details-marker { display:none; }
    .dinv-panel > summary:hover { background:var(--ax-panel,#f9fafb); }
    .dinv-panel > summary .dinv-chev { margin-left:auto; transition:transform .18s ease; color:var(--ax-faint,#9ca3af); font-size:.8rem; }
    .dinv-panel[open] > summary .dinv-chev { transform:rotate(90deg); }
    .dinv-panel > summary .dinv-count { font-weight:600; font-size:.72rem; color:var(--ax-faint,#6b7280); background:var(--ax-neutral-soft,#f3f4f6); padding:.1rem .5rem; border-radius:9999px; }
    .dinv-body { padding:.25rem 1.15rem 1.15rem; }
    .dinv-empty { padding:1.1rem; font-size:.8rem; color:var(--ax-faint,#6b7280); background:var(--ax-panel,#f9fafb); border:1px dashed var(--ax-line,#e5e7eb); border-radius:.6rem; line-height:1.55; }
    .dinv-pill { display:inline-flex; align-items:center; gap:.3rem; padding:.15rem .55rem; border-radius:9999px; font-size:.72rem; font-weight:700; }
    .dinv-pill.s-success { background:var(--ax-success-soft,#dcfce7); color:var(--ax-success-fg,#15803d); }
    .dinv-pill.s-info    { background:var(--ax-info-soft,#dbeafe); color:var(--ax-info-fg,#1d4ed8); }
    .dinv-pill.s-warning { background:var(--ax-warning-soft,#fef3c7); color:var(--ax-warning-fg,#b45309); }
    .dinv-pill.s-danger  { background:var(--ax-danger-soft,#fee2e2); color:var(--ax-danger-fg,#b91c1c); }
    .dinv-pill.s-gray    { background:var(--ax-neutral-soft,#f3f4f6); color:var(--ax-neutral-fg,#4b5563); }
    /* Cause map */
    .dinv-map { height:360px; width:100%; border:1px solid var(--ax-line,#e5e7eb); border-radius:.6rem; background:var(--ax-panel,#fafafa); }
    .dinv-map-note { font-size:.75rem; color:var(--ax-faint,#6b7280); margin-top:.55rem; line-height:1.5; }
    .dinv-fallback { margin-top:.6rem; font-size:.8rem; }
    .dinv-fallback .row { padding:.35rem 0; border-bottom:1px solid var(--ax-line-2,#f1f5f9); color:var(--ax-text,#374151); }
    /* Trail */
    .dinv-trail { position:relative; padding-left:1.1rem; }
    .dinv-trail::before { content:''; position:absolute; left:.28rem; top:.3rem; bottom:.3rem; width:2px; background:var(--ax-line,#e5e7eb); }
    .dinv-trail-item { position:relative; padding:.4rem 0 .55rem .7rem; }
    .dinv-trail-item::before { content:''; position:absolute; left:-.86rem; top:.65rem; width:.55rem; height:.55rem; border-radius:9999px; background:var(--ax-accent-strong,#7c3aed); box-shadow:0 0 0 3px var(--ax-bg,#fff); }
    .dinv-trail-item.k-detected::before { background:#94a3b8; }
    .dinv-trail-item.k-cleared::before { background:#16a34a; }
    .dinv-trail-meta { font-size:.72rem; color:var(--ax-faint,#6b7280); }
    .dinv-trail-summary { font-size:.82rem; color:var(--ax-text,#1f2937); margin-top:.1rem; line-height:1.45; }
    .dinv-trail-actor { font-weight:700; color:var(--ax-text,#374151); }
    /* Confidence */
    .dinv-conf-grid { display:grid; grid-template-columns:1fr; gap:1rem; }
    @media(min-width:720px){ .dinv-conf-grid { grid-template-columns:1fr 1fr; } }
    .dinv-conf-card { border:1px solid var(--ax-line,#e5e7eb); border-radius:.6rem; padding:.85rem 1rem; }
    .dinv-conf-card h4 { margin:0 0 .5rem; font-size:.7rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:var(--ax-faint,#6b7280); }
    .dinv-score { height:.5rem; border-radius:9999px; background:var(--ax-neutral-soft,#f1f5f9); overflow:hidden; margin:.45rem 0; }
    .dinv-score > span { display:block; height:100%; border-radius:9999px; }
    .dinv-kv { display:flex; justify-content:space-between; font-size:.78rem; padding:.2rem 0; color:var(--ax-text,#374151); }
    .dinv-kv b { font-weight:700; }
    .dinv-bars { display:flex; flex-wrap:wrap; gap:.4rem; }
    .dinv-explain { font-size:.78rem; color:var(--ax-text,#374151); line-height:1.5; margin-top:.5rem; background:var(--ax-panel,#f9fafb); border-radius:.5rem; padding:.55rem .7rem; }
    /* Evidence */
    .dinv-chips { display:flex; flex-wrap:wrap; gap:.35rem; margin-bottom:.75rem; }
    .dinv-chip { border:1px solid var(--ax-line,#e5e7eb); background:var(--ax-bg,#fff); border-radius:9999px; padding:.2rem .65rem; font-size:.74rem; font-weight:600; color:var(--ax-text,#374151); cursor:pointer; }
    .dinv-chip:hover { background:var(--ax-panel,#f9fafb); }
    .dinv-chip-on { background:var(--ax-accent-strong,#7c3aed); color:#fff; border-color:var(--ax-accent-strong,#7c3aed); }
    .dinv-ev-table { width:100%; border-collapse:collapse; font-size:.8rem; }
    .dinv-ev-table td { padding:.5rem .6rem; border-bottom:1px solid var(--ax-line-2,#f1f5f9); vertical-align:top; color:var(--ax-text,#374151); }
    .dinv-ev-label { font-weight:600; color:var(--ax-ink,#111827); }
    .dinv-ev-val { font-weight:700; white-space:nowrap; text-align:right; }
    .dinv-ev-src { font-size:.7rem; color:var(--ax-faint,#9ca3af); }
    /* Impact */
    .dinv-impact-split { display:grid; grid-template-columns:1fr; gap:1rem; }
    @media(min-width:720px){ .dinv-impact-split { grid-template-columns:1fr 1fr; } }
    .dinv-impact-card { border:1px solid var(--ax-line,#e5e7eb); border-radius:.6rem; padding:.9rem 1rem; }
    .dinv-impact-card .lab { font-size:.68rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:var(--ax-faint,#6b7280); }
    .dinv-impact-card .big { font-size:1.55rem; font-weight:800; color:var(--ax-ink,#111827); margin-top:.2rem; letter-spacing:-.01em; }
    .dinv-impact-card.est { background:var(--ax-warning-soft,#fffbeb); border-color:#fde68a; }
    .dinv-impact-card.meas { background:var(--ax-success-soft,#f0fdf4); border-color:#bbf7d0; }
    .dinv-bar-row { display:flex; align-items:center; gap:.6rem; padding:.3rem 0; font-size:.78rem; }
    .dinv-bar-row .nm { flex:0 0 40%; color:var(--ax-text,#374151); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .dinv-bar-row .bar { flex:1; height:.55rem; background:var(--ax-neutral-soft,#f1f5f9); border-radius:9999px; overflow:hidden; }
    .dinv-bar-row .bar > span { display:block; height:100%; background:var(--ax-warning-fg,#d97706); border-radius:9999px; }
    .dinv-bar-row .amt { flex:0 0 auto; font-weight:700; color:var(--ax-ink,#111827); }
    .dinv-note { font-size:.73rem; color:var(--ax-faint,#6b7280); margin-top:.7rem; line-height:1.5; font-style:italic; }
    </style>

    {{-- NB: use inline php(...) directives only in this section — never a
         php/endphp block. Blade's storePhpBlocks pass pairs the first inline
         php-open with any later endphp token and swallows the wrapper directive,
         breaking compilation (a page 500). Do not write those tokens even in a
         comment: this pass runs before comments are stripped. --}}
    @php($confTierClass = ['verified'=>'s-success','likely'=>'s-info','correlated'=>'s-warning','single'=>'s-gray'])
    @php($sigClass = ['established'=>'s-success','probable'=>'s-info','suspected'=>'s-warning','unknown'=>'s-gray'])
    @php($dirClass = ['supports'=>'s-danger','contradicts'=>'s-success','neutral'=>'s-gray'])
    @php($cur = $imp['currency'] ?? '')
    @php($money = fn($n) => $cur . number_format((float) $n, floor((float) $n) == (float) $n ? 0 : 2))

    <div class="dinv-wrap">
        <div class="dinv-head">
            <span class="dinv-kicker">Deep Investigation</span>
            <h2>Drill down</h2>
        </div>
        <p class="dinv-sub">An optional, interactive layer on top of the analysis above. It re-projects the same governed
            evidence — it never adds new facts. Where a source hasn't produced data yet, the module says so plainly.</p>

        {{-- ── Cause Map ─────────────────────────────────────────────────── --}}
        <details class="dinv-panel" open>
            <summary>
                <span>🕸️ Cause Map</span>
                @if(($cm['available'] ?? false))
                    <span class="dinv-pill {{ $confTierClass[$cm['structure'] ?? 'single'] ?? 's-gray' }}">
                        {{ ['verified'=>'Verified chain','likely'=>'Likely chain','correlated'=>'Correlated only','single'=>'Single signal'][$cm['structure'] ?? 'single'] ?? ucfirst($cm['structure'] ?? '') }}
                    </span>
                @endif
                <span class="dinv-chev">▶</span>
            </summary>
            <div class="dinv-body">
                @if(!($cm['available'] ?? false))
                    <div class="dinv-empty">{{ $cm['empty_reason'] ?? 'Evidence unavailable.' }}</div>
                @else
                    <div wire:ignore>
                        <div id="dinv-causemap" class="dinv-map"></div>
                        <script type="application/json" id="dinv-causemap-data">@json(['nodes' => $cm['nodes'], 'edges' => $cm['edges']])</script>
                    </div>
                    {{-- Text fallback (also the accessible view if JS/canvas is unavailable) --}}
                    <div class="dinv-fallback">
                        @forelse($cm['edges'] as $e)
                            <div class="row">{{ $e['cause_label'] }} <span style="color:var(--ax-accent-strong,#7c3aed)">→</span> {{ $e['effect_label'] }} <span class="dinv-ev-src">· {{ $e['scope_label'] }}</span></div>
                        @empty
                            <div class="row">{{ count($cm['nodes']) >= 2 ? 'These signals co-occur but form no known cause→effect chain — treat as correlated, not causally linked.' : 'A single signal — no causal chain to draw yet.' }}</div>
                        @endforelse
                    </div>
                    @if(!empty($cm['inference']))
                        <div class="dinv-explain">{{ $cm['inference']['explanation'] }}</div>
                    @endif
                    <div class="dinv-map-note">The root cause (highlighted) and the chain are inferred deterministically from the retail causal graph — the AI does not choose them. Drag to explore; scroll to zoom.</div>
                @endif
            </div>
        </details>

        {{-- ── Investigation Trail ───────────────────────────────────────── --}}
        <details class="dinv-panel">
            <summary>
                <span>🧭 Investigation Trail</span>
                @if(($tr['available'] ?? false))<span class="dinv-count">{{ count($tr['events']) }} events</span>@endif
                <span class="dinv-chev">▶</span>
            </summary>
            <div class="dinv-body">
                @if(!($tr['available'] ?? false))
                    <div class="dinv-empty">{{ $tr['empty_reason'] ?? 'Evidence unavailable.' }}</div>
                @else
                    <div class="dinv-trail">
                        @foreach($tr['events'] as $e)
                            <div class="dinv-trail-item k-{{ $e['kind'] }}">
                                <div class="dinv-trail-meta">{{ $e['at_label'] }} · {{ $e['at_human'] }}</div>
                                <div class="dinv-trail-summary"><span class="dinv-trail-actor">{{ $e['actor'] }}</span> — {{ $e['summary'] }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </details>

        {{-- ── Confidence Explorer ───────────────────────────────────────── --}}
        <details class="dinv-panel">
            <summary>
                <span>🎯 Confidence Explorer</span>
                @if(!empty($cf['causal']))<span class="dinv-pill {{ $confTierClass[$cf['causal']['tier']] ?? 's-gray' }}">{{ ucfirst($cf['causal']['tier']) }} · {{ $cf['causal']['score'] }}%</span>@endif
                <span class="dinv-chev">▶</span>
            </summary>
            <div class="dinv-body">
                @if(!($cf['available'] ?? false))
                    <div class="dinv-empty">{{ $cf['empty_reason'] ?? 'Evidence unavailable.' }}</div>
                @else
                    <div class="dinv-conf-grid">
                        <div class="dinv-conf-card">
                            <h4>Causal inference</h4>
                            @if(!empty($cf['causal']))
                                @php($ct = $cf['causal'])
                                <div class="dinv-kv"><span>Strength</span><b><span class="dinv-pill {{ $confTierClass[$ct['tier']] ?? 's-gray' }}">{{ ucfirst($ct['tier']) }}</span></b></div>
                                <div class="dinv-score"><span style="width:{{ max(4,(int)$ct['score']) }}%;background:var(--ax-accent-strong,#7c3aed)"></span></div>
                                <div class="dinv-kv"><span>Confidence score</span><b>{{ $ct['score'] }}%</b></div>
                                <div class="dinv-kv"><span>Causal links found</span><b>{{ $ct['links'] }}</b></div>
                                <div class="dinv-kv"><span>Alternative root causes</span><b>{{ $ct['alternatives'] }}</b></div>
                                <div class="dinv-explain">{{ $ct['explanation'] }}</div>
                            @else
                                <div class="dinv-empty">Causal inference needs at least two linked signals. Not enough to infer a chain here.</div>
                            @endif
                        </div>
                        <div class="dinv-conf-card">
                            <h4>Per-signal confidence &amp; evidence</h4>
                            @if(!empty($cf['signals']))
                                <div class="dinv-bars" style="margin-bottom:.6rem">
                                    @foreach($cf['signals'] as $s)
                                        <span class="dinv-pill {{ $sigClass[$s['tier']] ?? 's-gray' }}">{{ $s['label'] }} · {{ $s['count'] }}</span>
                                    @endforeach
                                </div>
                            @endif
                            <div class="dinv-kv"><span>Evidence supporting the signal</span><b style="color:var(--ax-danger-fg,#b91c1c)">{{ $cf['backing']['supports'] }}</b></div>
                            <div class="dinv-kv"><span>Evidence contradicting (possible false positive)</span><b style="color:var(--ax-success-fg,#15803d)">{{ $cf['backing']['contradicts'] }}</b></div>
                            <div class="dinv-kv"><span>Neutral / context</span><b>{{ $cf['backing']['neutral'] }}</b></div>
                            <div class="dinv-kv" style="border-top:1px solid var(--ax-line-2,#f1f5f9);margin-top:.3rem;padding-top:.4rem"><span>Strong / moderate / weak</span><b>{{ $cf['strength']['strong'] }} / {{ $cf['strength']['moderate'] }} / {{ $cf['strength']['weak'] }}</b></div>
                        </div>
                    </div>
                    @php($caveats = $this->dataHealthCaveats())
                    @if(!empty($caveats))
                        <div class="dinv-explain" style="margin-top:.85rem">
                            <b>Data-health caveats:</b>
                            @foreach($caveats as $cav)<div style="margin-top:.2rem">• {{ $cav }}</div>@endforeach
                        </div>
                    @endif
                @endif
            </div>
        </details>

        {{-- ── Evidence Explorer ─────────────────────────────────────────── --}}
        <details class="dinv-panel">
            <summary>
                <span>🔍 Evidence Explorer</span>
                @if(($ev['available'] ?? false))<span class="dinv-count">{{ $ev['count'] }} items</span>@endif
                <span class="dinv-chev">▶</span>
            </summary>
            <div class="dinv-body">
                @if(!($ev['available'] ?? false))
                    <div class="dinv-empty">{{ $ev['empty_reason'] ?? 'Evidence unavailable.' }}</div>
                @else
                    <div class="dinv-chips">
                        <button type="button" class="dinv-chip dinv-chip-on" onclick="dinvFilter('direction','all',this)">All</button>
                        @foreach($ev['facets']['directions'] as $d)
                            <button type="button" class="dinv-chip" onclick="dinvFilter('direction','{{ $d }}',this)">{{ ucfirst($d) }}</button>
                        @endforeach
                    </div>
                    <table class="dinv-ev-table"><tbody id="dinv-evidence-rows">
                        @foreach($ev['items'] as $it)
                            <tr data-ev data-direction="{{ $it['direction'] }}" data-signal="{{ $it['signal'] }}">
                                <td>
                                    <div class="dinv-ev-label">{{ $it['label'] }}</div>
                                    <div class="dinv-ev-src">{{ $it['signal'] }}@if($it['source']) · {{ $it['source'] }}@endif @if($it['observed_at'])· {{ $it['observed_at'] }}@endif</div>
                                </td>
                                <td><span class="dinv-pill {{ $dirClass[$it['direction']] ?? 's-gray' }}">{{ ucfirst($it['direction']) }}</span></td>
                                <td class="dinv-ev-val">{{ $it['value'] }}</td>
                            </tr>
                        @endforeach
                    </tbody></table>
                @endif
            </div>
        </details>

        {{-- ── Impact Explorer ───────────────────────────────────────────── --}}
        <details class="dinv-panel">
            <summary>
                <span>💰 Impact Explorer</span>
                @if(($imp['available'] ?? false) && ($imp['total_at_risk'] ?? 0) > 0)<span class="dinv-count">{{ $money($imp['total_at_risk']) }} at risk</span>@endif
                <span class="dinv-chev">▶</span>
            </summary>
            <div class="dinv-body">
                @if(!($imp['available'] ?? false))
                    <div class="dinv-empty">{{ $imp['empty_reason'] ?? 'Evidence unavailable.' }}</div>
                @else
                    <div class="dinv-impact-split">
                        <div class="dinv-impact-card est">
                            <div class="lab">Estimated value at risk</div>
                            <div class="big">{{ $money($imp['total_at_risk']) }}</div>
                            @if(!is_null($imp['ai_estimate']))<div class="dinv-ev-src" style="margin-top:.25rem">Investigation estimate: {{ $money($imp['ai_estimate']) }}</div>@endif
                        </div>
                        <div class="dinv-impact-card meas">
                            <div class="lab">Measured recovery</div>
                            @if(!empty($imp['measured']))
                                @php($ms = $imp['measured'])
                                <div class="big">{{ !is_null($ms['observed_recovery']) ? $money($ms['observed_recovery']) : '—' }}</div>
                                <div class="dinv-ev-src" style="margin-top:.25rem">
                                    <span class="dinv-pill s-success">{{ $ms['state_label'] }}</span>
                                    @if($ms['attribution_label']) · attribution: {{ $ms['attribution_label'] }}@endif
                                    @if(!empty($ms['net'])) · net of cost: {{ $money($ms['net']) }}@endif
                                </div>
                            @else
                                <div class="big" style="color:var(--ax-faint,#9ca3af)">Not yet measured</div>
                                <div class="dinv-ev-src" style="margin-top:.25rem">Recovery is measured over a monitoring window after an action is taken.</div>
                            @endif
                        </div>
                    </div>

                    @if(!empty($imp['breakdown']))
                        @php($mx = max(array_column($imp['breakdown'], 'amount')) ?: 1)
                        <div style="margin-top:1rem">
                            <h4 style="margin:0 0 .5rem;font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--ax-faint,#6b7280)">Value at risk by signal</h4>
                            @foreach($imp['breakdown'] as $b)
                                <div class="dinv-bar-row">
                                    <span class="nm">{{ $b['signal'] }}@if($b['sku']) · {{ $b['sku'] }}@endif</span>
                                    <span class="bar"><span style="width:{{ max(3, round($b['amount'] / $mx * 100)) }}%"></span></span>
                                    <span class="amt">{{ $money($b['amount']) }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if(!empty($imp['metrics']))
                        <div style="margin-top:1rem">
                            <h4 style="margin:0 0 .5rem;font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--ax-faint,#6b7280)">Measured metrics</h4>
                            <table class="dinv-ev-table"><tbody>
                                @foreach($imp['metrics'] as $m)
                                    <tr><td class="dinv-ev-label">{{ $m['metric'] }}<div class="dinv-ev-src">{{ $m['window'] }}</div></td>
                                        <td>baseline {{ $m['baseline'] !== null ? number_format((float)$m['baseline'],2) : '—' }} → observed {{ $m['observed'] !== null ? number_format((float)$m['observed'],2) : '—' }}</td>
                                        <td class="dinv-ev-val">{{ !is_null($m['recovery']) ? $money($m['recovery']) : '—' }}</td></tr>
                                @endforeach
                            </tbody></table>
                        </div>
                    @endif

                    <div class="dinv-note">{{ $imp['note'] }}</div>
                @endif
            </div>
        </details>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/vis-network/9.1.9/dist/vis-network.min.js" defer></script>
    <script>
    (function () {
        function initDeepCauseMap() {
            var el = document.getElementById('dinv-causemap');
            if (!el || el.dataset.rendered) return;
            if (typeof vis === 'undefined') return; // text fallback stays visible
            var dataEl = document.getElementById('dinv-causemap-data');
            if (!dataEl) return;
            try {
                var payload = JSON.parse(dataEl.textContent);
                var nodes = new vis.DataSet((payload.nodes || []).map(function (n) {
                    var color = n.is_root
                        ? { background: '#7c3aed', border: '#6d28d9' }
                        : (n.severity === 'high' ? { background: '#fee2e2', border: '#ef4444' }
                            : n.severity === 'medium' ? { background: '#ffedd5', border: '#f97316' }
                                : { background: '#f1f5f9', border: '#94a3b8' });
                    return {
                        id: n.id,
                        label: n.label + (n.sku ? '\n(' + n.sku + ')' : ''),
                        shape: 'box',
                        color: color,
                        font: { color: n.is_root ? '#ffffff' : '#111827', size: 13 },
                        borderWidth: n.is_root ? 3 : 1
                    };
                }));
                var edges = new vis.DataSet((payload.edges || []).map(function (e) {
                    return {
                        from: e.from, to: e.to, arrows: 'to', label: e.scope_label,
                        font: { size: 10, color: '#6b7280', align: 'middle', background: '#ffffff' },
                        color: { color: '#c084fc', highlight: '#7c3aed' },
                        smooth: { type: 'cubicBezier' }
                    };
                }));
                var hasEdges = (payload.edges || []).length > 0;
                new vis.Network(el, { nodes: nodes, edges: edges }, {
                    layout: { hierarchical: { enabled: hasEdges, direction: 'LR', sortMethod: 'directed', levelSeparation: 190, nodeSpacing: 130 } },
                    physics: { enabled: !hasEdges },
                    interaction: { hover: true, zoomView: true, dragView: true, dragNodes: true },
                    nodes: { margin: 10, widthConstraint: { maximum: 170 } }
                });
                el.dataset.rendered = '1';
            } catch (err) { /* leave the text fallback in place */ }
        }
        window.dinvFilter = function (kind, val, btn) {
            var group = btn.parentNode;
            group.querySelectorAll('.dinv-chip').forEach(function (b) { b.classList.remove('dinv-chip-on'); });
            btn.classList.add('dinv-chip-on');
            document.querySelectorAll('#dinv-evidence-rows [data-ev]').forEach(function (r) {
                r.style.display = (val === 'all' || r.getAttribute('data-' + kind) === val) ? '' : 'none';
            });
        };
        document.addEventListener('DOMContentLoaded', function () { setTimeout(initDeepCauseMap, 250); });
        window.addEventListener('load', initDeepCauseMap);
        document.addEventListener('livewire:navigated', function () { setTimeout(initDeepCauseMap, 250); });
        // Re-init when the Cause Map panel is first expanded (canvas needs a visible container).
        document.addEventListener('toggle', function (ev) {
            if (ev.target && ev.target.querySelector && ev.target.querySelector('#dinv-causemap')) {
                setTimeout(initDeepCauseMap, 50);
            }
        }, true);
    })();
    </script>
    @endif

</div>

</x-filament-panels::page>
