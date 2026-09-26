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
// WP4.4: stock value at cost is reported beside revenue at risk, never added to it.
if ((float) ($record->capital_at_risk ?? 0) > 0) {
    $impactText .= ' Stock value involved (at cost): ' . $curr . number_format($record->capital_at_risk, 2) . '.';
}

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
            <span class="inv2-infobar-right-value">{{ \App\Support\Tenancy\TenantClock::display($record->opened_at)?->format('M j, Y g:i A') ?? '—' }}</span>
        </div>
        <div class="inv2-infobar-right-row">
            <span class="inv2-infobar-right-label">Investigated At</span>
            <span class="inv2-infobar-right-value">{{ \App\Support\Tenancy\TenantClock::display($record->ai_generated_at)?->format('M j, Y g:i A') ?? '—' }}</span>
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
    $causalTier = ['corroborated' => 'success', 'verified' => 'success', 'likely' => 'warning', 'correlated' => null];
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
                <span class="ax-chain-node {{ $i === 0 ? 'ax-chain-node--root' : '' }}">{{ $step['label'] }} @if(! empty($step['onset']))<span class="ax-faint ax-text-xs" title="{{ ($step['onset_source'] ?? '') === 'data' ? 'Start read from the data: ' . ($step['onset_basis'] ?? '') : 'Start not in the data: first flagged' }}">· {{ ($step['onset_source'] ?? '') === 'data' ? 'from' : 'flagged' }} {{ \Illuminate\Support\Carbon::parse($step['onset'])->format('j M') }}</span>@endif</span>
            @endforeach
        </div>
        <p class="ax-muted ax-text-sm ax-lh ax-mt-3">{{ $causal['explanation'] }}</p>
        @if(! empty($causal['timing']))
            <p class="ax-faint ax-text-xs ax-mt-2">Timing: {{ $causal['timing']['in_order'] }} link(s) in order in the data, {{ $causal['timing']['unverified'] }} not confirmed{{ $causal['timing']['reversed'] > 0 ? ', ' . $causal['timing']['reversed'] . ' left out (cause started after the effect)' : '' }}.</p>
        @endif
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
                <span class="inv2-action-value">{{ \App\Support\Tenancy\TenantClock::display($completedAction->updated_at)?->format('M j, Y g:i A') ?? '—' }}</span>
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
                <span class="inv2-action-value">{{ \App\Support\Tenancy\TenantClock::display($record->resolved_at)->format('M j, Y g:i A') }}</span>
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
                    <span style="color:var(--ax-danger)">At Risk: {{ $curr }}{{ number_format($outcome->revenue_at_risk,0) }}</span>
                    @endif
                    @if($outcome->observed_recovery)
                    <span style="color:var(--ax-success)">Recovered: {{ $curr }}{{ number_format($outcome->observed_recovery,0) }}</span>
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
                Snoozed until {{ \App\Support\Tenancy\TenantClock::display($record->snoozed_until)->format('M j, Y') }}
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
    @php $deep = $this->deepInvestigation(); @endphp
    @if(!empty($deep))
    @php $cm = $deep['cause_map'] ?? []; @endphp
    @php $tr = $deep['trail'] ?? []; @endphp
    @php $cf = $deep['confidence'] ?? []; @endphp
    @php $ev = $deep['evidence'] ?? []; @endphp
    @php $imp = $deep['impact'] ?? []; @endphp
    @php $wc = $deep['what_changed'] ?? []; @endphp
    @php $wr = $deep['why_rec'] ?? []; @endphp
    @php $wi = $deep['what_if'] ?? []; @endphp
    @php $si = $deep['similar'] ?? []; @endphp
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
    /* Cause map — fishbone */
    .dinv-fishwrap { width:100%; overflow-x:auto; border:1px solid var(--ax-line,#e5e7eb); border-radius:.6rem; background:var(--ax-panel,#fafafa); padding:.5rem; }
    .dinv-fish { width:100%; min-width:640px; height:auto; display:block; }
    .dinv-map-note { font-size:.75rem; color:var(--ax-faint,#6b7280); margin-top:.6rem; line-height:1.5; }
    .dinv-fish-cat { font-size:13px; font-weight:800; fill:var(--ax-muted,#475569); text-transform:uppercase; letter-spacing:.04em; }
    .dinv-fish-sig { font-size:12px; fill:var(--ax-text,#334155); }
    .dinv-fish-head-k { font-size:10px; font-weight:800; fill:var(--ax-accent-strong,#7c3aed); letter-spacing:.08em; }
    .dinv-fish-head { font-size:14px; font-weight:800; fill:var(--ax-ink,#111827); }
    .dinv-fish-head-sku { font-size:11px; fill:var(--ax-faint,#6b7280); }
    .dinv-fish-node { cursor:pointer; }
    .dinv-fish-node circle { stroke:#fff; stroke-width:1.5; transition:r .12s ease; }
    .dinv-fish-node:hover circle { r:8; }
    .dinv-fish-node:hover .dinv-fish-sig { fill:var(--ax-accent-strong,#7c3aed); font-weight:700; }
    .dinv-fish-node.sel circle { stroke:var(--ax-accent-strong,#7c3aed); stroke-width:3; }
    .dinv-fish-node.sel .dinv-fish-sig { fill:var(--ax-accent-strong,#7c3aed); font-weight:800; }
    .dinv-fish-node.is-root circle { stroke:var(--ax-accent-strong,#7c3aed); stroke-width:2.5; }
    .dinv-sev-high { fill:#ef4444; }
    .dinv-sev-medium { fill:#f97316; }
    .dinv-sev-low { fill:#94a3b8; }
    .dinv-fish-node.is-root .dinv-sev-high, .dinv-fish-node.is-root .dinv-sev-medium, .dinv-fish-node.is-root .dinv-sev-low { fill:var(--ax-accent-strong,#7c3aed); }
    /* Cause-map click drawer */
    .dinv-drawer { margin-top:.75rem; border:1px solid var(--ax-accent-soft-border,#e9d5ff); background:var(--ax-accent-soft,#faf5ff); border-radius:.6rem; padding:.75rem .9rem; }
    .dinv-drawer-head { display:flex; align-items:center; justify-content:space-between; font-size:.85rem; font-weight:700; color:var(--ax-ink,#111827); margin-bottom:.5rem; gap:.5rem; }
    .dinv-drawer-x { border:none; background:transparent; cursor:pointer; color:var(--ax-faint,#6b7280); font-size:.9rem; line-height:1; padding:.1rem .3rem; }
    .dinv-drawer-kv { display:flex; justify-content:space-between; font-size:.8rem; padding:.15rem 0; color:var(--ax-text,#374151); }
    .dinv-drawer-evh { font-size:.68rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:var(--ax-faint,#6b7280); margin:.5rem 0 .25rem; }
    .dinv-drawer-ev { font-size:.8rem; color:var(--ax-text,#374151); padding:.2rem 0; display:flex; align-items:center; gap:.4rem; }
    .dinv-drawer-ev .dot { width:.5rem; height:.5rem; border-radius:9999px; flex:0 0 auto; }
    .dinv-drawer-ev.muted { color:var(--ax-faint,#9ca3af); }
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
    /* What Changed */
    .dinv-wc-head { font-size:.72rem; font-weight:800; text-transform:uppercase; letter-spacing:.05em; color:var(--ax-faint,#6b7280); margin-bottom:.5rem; }
    .dinv-wc-head span { font-weight:600; text-transform:none; letter-spacing:0; }
    .dinv-wc-chart { position:relative; display:flex; align-items:flex-end; gap:2px; height:120px; padding:.3rem .1rem; border-bottom:1px solid var(--ax-line,#e5e7eb); }
    .dinv-wc-col { flex:1 1 0; height:100%; display:flex; align-items:flex-end; justify-content:center; min-width:3px; }
    .dinv-wc-bar { width:100%; max-width:16px; background:var(--ax-accent-strong,#7c3aed); border-radius:2px 2px 0 0; opacity:.85; }
    .dinv-wc-baseline { position:absolute; left:0; right:0; bottom:var(--wc-base,0%); border-top:1.5px dashed var(--ax-warning-fg,#b45309); }
    .dinv-wc-baseline::after { content:'baseline'; position:absolute; right:0; top:-1.1rem; font-size:.62rem; color:var(--ax-warning-fg,#b45309); }
    .dinv-wc-markers { display:flex; flex-wrap:wrap; gap:.4rem; margin-top:.9rem; }
    /* Why This Recommendation */
    .dinv-wr-item { border:1px solid var(--ax-line,#e5e7eb); border-radius:.6rem; padding:.75rem .9rem; margin-bottom:.6rem; }
    .dinv-wr-title { font-weight:700; font-size:.85rem; color:var(--ax-ink,#111827); display:flex; align-items:center; gap:.5rem; flex-wrap:wrap; }
    .dinv-wr-rat { font-size:.8rem; color:var(--ax-text,#374151); line-height:1.5; margin-top:.4rem; }
    .dinv-wr-deriv { display:flex; flex-wrap:wrap; gap:.35rem .9rem; margin-top:.55rem; font-size:.76rem; color:var(--ax-muted,#4b5563); }
    .dinv-wr-deriv b { color:var(--ax-ink,#111827); }
    /* What-If simulator */
    .dinv-sim-banner { display:flex; align-items:center; gap:.5rem; font-size:.72rem; font-weight:800; letter-spacing:.05em; color:var(--ax-warning-fg); background:var(--ax-warning-soft); border:1px solid var(--ax-warning-line); border-radius:.5rem; padding:.4rem .7rem; margin-bottom:.85rem; }
    .dinv-sim-sub { font-weight:600; letter-spacing:0; color:var(--ax-warning-fg); }
    .dinv-hz { display:inline-flex; gap:.3rem; margin-bottom:.9rem; }
    .dinv-hz button { border:1px solid var(--ax-line,#e5e7eb); background:var(--ax-bg,#fff); border-radius:9999px; padding:.2rem .7rem; font-size:.74rem; font-weight:700; color:var(--ax-text,#374151); cursor:pointer; }
    .dinv-hz button.on { background:var(--ax-accent-strong,#7c3aed); color:#fff; border-color:var(--ax-accent-strong,#7c3aed); }
    .dinv-sim-grid { display:grid; grid-template-columns:1fr; gap:.75rem; }
    @media(min-width:640px){ .dinv-sim-grid { grid-template-columns:1fr 1fr; } }
    .dinv-sim-card { border:1px solid var(--ax-line,#e5e7eb); border-radius:.6rem; padding:.85rem 1rem; }
    .dinv-sim-card.no { background:var(--ax-danger-soft); border-color:var(--ax-danger-line); }
    .dinv-sim-card.act { background:var(--ax-success-soft); border-color:var(--ax-success-line); }
    .dinv-sim-card .lab { font-size:.68rem; font-weight:800; text-transform:uppercase; letter-spacing:.05em; color:var(--ax-faint,#6b7280); }
    .dinv-sim-card .big { font-size:1.4rem; font-weight:800; color:var(--ax-ink,#111827); margin-top:.15rem; }
    .dinv-sim-card .sub { font-size:.76rem; color:var(--ax-muted,#4b5563); margin-top:.2rem; }
    .dinv-sim-protect { margin-top:.75rem; font-size:.85rem; font-weight:700; color:var(--ax-success-fg,#15803d); }
    .dinv-sim-assum { margin-top:.8rem; font-size:.74rem; color:var(--ax-faint,#6b7280); line-height:1.55; }
    .dinv-sim-assum div { padding:.1rem 0 .1rem .8rem; position:relative; }
    .dinv-sim-assum div::before { content:'·'; position:absolute; left:.2rem; }
    /* Similar incidents */
    .dinv-sim-inc { border:1px solid var(--ax-line,#e5e7eb); border-radius:.6rem; padding:.7rem .9rem; margin-bottom:.55rem; }
    .dinv-sim-inc .t { font-weight:700; font-size:.83rem; color:var(--ax-ink,#111827); }
    .dinv-sim-inc .m { font-size:.72rem; color:var(--ax-accent-strong,#7c3aed); font-weight:600; margin-top:.1rem; }
    .dinv-sim-inc .r { display:flex; flex-wrap:wrap; gap:.35rem .9rem; margin-top:.45rem; font-size:.76rem; color:var(--ax-muted,#4b5563); }
    .dinv-sim-inc .r b { color:var(--ax-ink,#111827); }
    .dinv-sim-inc .pb { margin-top:.5rem; padding:.4rem .6rem; border-radius:.45rem; background:var(--ax-accent-soft,#f5f3ff); font-size:.78rem; color:var(--ax-text,#374151); line-height:1.45; }
    .dinv-sim-inc .pb b { color:var(--ax-accent-strong,#7c3aed); }
    /* What-If transfer alternative */
    .dinv-tr { margin-top:.9rem; border:1px solid var(--ax-success-line); background:var(--ax-success-soft); border-radius:.6rem; padding:.8rem 1rem; }
    .dinv-tr-head { display:flex; align-items:center; gap:.5rem; font-size:.82rem; font-weight:800; color:var(--ax-success-fg); }
    .dinv-tr-lead { margin:.5rem 0 0; font-size:.84rem; line-height:1.55; color:var(--ax-ink,#111827); }
    .dinv-tr-lead b { color:var(--ax-ink,#111827); }
    .dinv-tr-body .dinv-sim-assum { margin-top:.55rem; }
    </style>

    {{-- NB: use inline php(...) directives only in this section — never a
         php/endphp block. Blade's storePhpBlocks pass pairs the first inline
         php-open with any later endphp token and swallows the wrapper directive,
         breaking compilation (a page 500). Do not write those tokens even in a
         comment: this pass runs before comments are stripped. --}}
    @php $confTierClass = ['corroborated'=>'s-success','verified'=>'s-success','likely'=>'s-info','correlated'=>'s-warning','single'=>'s-gray']; @endphp
    @php $sigClass = ['established'=>'s-success','probable'=>'s-info','suspected'=>'s-warning','unknown'=>'s-gray']; @endphp
    @php $dirClass = ['supports'=>'s-danger','contradicts'=>'s-success','neutral'=>'s-gray']; @endphp
    @php $cur = $imp['currency'] ?? ''; @endphp
    @php $money = fn($n) => $cur . number_format((float) $n, floor((float) $n) == (float) $n ? 0 : 2); @endphp

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
                        {{ ['corroborated'=>'Corroborated chain','verified'=>'Corroborated chain','likely'=>'Likely chain','correlated'=>'Correlated only','single'=>'Single signal'][$cm['structure'] ?? 'single'] ?? ucfirst($cm['structure'] ?? '') }}
                    </span>
                @endif
                <span class="dinv-chev">▶</span>
            </summary>
            <div class="dinv-body">
                @if(!($cm['available'] ?? false))
                    <div class="dinv-empty">{{ $cm['empty_reason'] ?? 'Evidence unavailable.' }}</div>
                @else
                    @php $fb = $cm['fishbone']; @endphp
                    @php $detailMap = collect($cm['nodes'])->keyBy('id')->map(fn ($n) => $n['detail'])->all(); @endphp
                    <div class="dinv-fishwrap" wire:ignore>
                        <svg class="dinv-fish" viewBox="{{ $fb['viewbox'] }}" preserveAspectRatio="xMidYMid meet" role="img" aria-label="Fishbone cause-and-effect diagram">
                            <defs>
                                <marker id="dinv-arrow" markerWidth="9" markerHeight="9" refX="7" refY="3" orient="auto" markerUnits="strokeWidth">
                                    <path d="M0,0 L7,3 L0,6 Z" fill="var(--ax-accent-strong,#7c3aed)"></path>
                                </marker>
                            </defs>
                            <line x1="{{ $fb['spine']['x1'] }}" y1="{{ $fb['spine']['y'] }}" x2="{{ $fb['spine']['x2'] }}" y2="{{ $fb['spine']['y'] }}" stroke="var(--ax-accent-strong,#7c3aed)" stroke-width="3" marker-end="url(#dinv-arrow)"></line>
                            @foreach($fb['bones'] as $b)
                                <line x1="{{ $b['x0'] }}" y1="{{ $b['y0'] }}" x2="{{ $b['x1'] }}" y2="{{ $b['y1'] }}" stroke="var(--ax-line,#cbd5e1)" stroke-width="2"></line>
                                <text x="{{ $b['label_x'] }}" y="{{ $b['label_y'] }}" text-anchor="end" class="dinv-fish-cat">{{ $b['label'] }}</text>
                            @endforeach
                            <g>
                                <rect x="{{ $fb['head_x'] + 8 }}" y="{{ $fb['cy'] - 36 }}" width="200" height="72" rx="11" fill="var(--ax-accent-soft,#f5f3ff)" stroke="var(--ax-accent-strong,#7c3aed)" stroke-width="2"></rect>
                                <text x="{{ $fb['head_x'] + 108 }}" y="{{ $fb['cy'] - 12 }}" text-anchor="middle" class="dinv-fish-head-k">EFFECT</text>
                                <text x="{{ $fb['head_x'] + 108 }}" y="{{ $fb['cy'] + 6 }}" text-anchor="middle" class="dinv-fish-head">{{ \Illuminate\Support\Str::limit($fb['head']['label'], 24) }}</text>
                                @if($fb['head']['sku'])<text x="{{ $fb['head_x'] + 108 }}" y="{{ $fb['cy'] + 24 }}" text-anchor="middle" class="dinv-fish-head-sku">{{ $fb['head']['sku'] }}</text>@endif
                            </g>
                            @foreach($fb['signals'] as $s)
                                <g class="dinv-fish-node {{ $s['is_root'] ? 'is-root' : '' }}" data-id="{{ $s['id'] }}" x-on:click="dinvNode({{ (int) $s['id'] }})" x-on:keydown.enter="dinvNode({{ (int) $s['id'] }})" tabindex="0" role="button" aria-label="{{ $s['label'] }}">
                                    <circle cx="{{ $s['cx'] }}" cy="{{ $s['cy'] }}" r="{{ $s['is_root'] ? 8 : 5 }}" class="dinv-sev-{{ $s['severity'] }}"></circle>
                                    <text x="{{ $s['label_x'] }}" y="{{ $s['label_y'] }}" text-anchor="end" class="dinv-fish-sig">{{ $s['label'] }}</text>
                                </g>
                            @endforeach
                        </svg>
                    </div>

                    <div id="dinv-fish-drawer" class="dinv-drawer" hidden>
                        <div class="dinv-drawer-head"><span id="dinv-drawer-title"></span><button type="button" class="dinv-drawer-x" x-on:click="dinvNodeClose()" aria-label="Close">✕</button></div>
                        <div id="dinv-drawer-body"></div>
                    </div>
                    <script type="application/json" id="dinv-fish-detail">@json($detailMap)</script>

                    @if(!empty($cm['inference']))
                        <div class="dinv-explain">{{ $cm['inference']['explanation'] }}</div>
                    @endif
                    <div class="dinv-map-note">Head = the downstream effect; each bone is a cause category, and the nodes on it are the contributing signals. The highlighted node is the deterministic root cause (from the retail causal graph — the AI does not choose it). <strong>Click any node</strong> for its evidence and confidence.</div>
                @endif
            </div>
        </details>

        {{-- ── What Changed ──────────────────────────────────────────────── --}}
        <details class="dinv-panel">
            <summary>
                <span>📈 What Changed</span>
                @if(($wc['available'] ?? false) && !empty($wc['markers']))<span class="dinv-count">{{ count($wc['markers']) }} shifts</span>@endif
                <span class="dinv-chev">▶</span>
            </summary>
            <div class="dinv-body">
                @if(!($wc['available'] ?? false))
                    <div class="dinv-empty">{{ $wc['empty_reason'] ?? 'Evidence unavailable.' }}</div>
                @else
                    @if(!empty($wc['series']))
                        <div class="dinv-wc-head">{{ $wc['series_label'] }} @if($wc['series_unit'])<span>({{ $wc['series_unit'] }})</span>@endif</div>
                        <div class="dinv-wc-chart" style="--wc-base:{{ ($wc['baseline'] && $wc['series_max'] > 0) ? round($wc['baseline'] / $wc['series_max'] * 100) : 0 }}%">
                            @if($wc['baseline'])<div class="dinv-wc-baseline"></div>@endif
                            @foreach($wc['series'] as $pt)
                                <div class="dinv-wc-col" title="{{ $pt['label'] }}: {{ $pt['value'] }}">
                                    <div class="dinv-wc-bar" style="height:{{ max(2, round($pt['value'] / max(0.001, $wc['series_max']) * 100)) }}%"></div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    @if(!empty($wc['markers']))
                        <div class="dinv-wc-markers">
                            @foreach($wc['markers'] as $m)
                                <span class="dinv-pill {{ $dirClass[$m['direction']] ?? 's-gray' }}">{{ $m['label'] }}: {{ $m['value'] }}</span>
                            @endforeach
                        </div>
                    @endif
                    <div class="dinv-note">Every value here is a governed evidence row — the observed series and the measured shifts against baseline. Nothing is modelled or predicted.</div>
                @endif
            </div>
        </details>

        {{-- ── Why This Recommendation ───────────────────────────────────── --}}
        <details class="dinv-panel">
            <summary>
                <span>🧭 Why This Recommendation</span>
                @if(($wr['available'] ?? false))<span class="dinv-count">{{ count($wr['items']) }}</span>@endif
                <span class="dinv-chev">▶</span>
            </summary>
            <div class="dinv-body">
                @if(!($wr['available'] ?? false))
                    <div class="dinv-empty">{{ $wr['empty_reason'] ?? 'Evidence unavailable.' }}</div>
                @else
                    @foreach($wr['items'] as $it)
                        <div class="dinv-wr-item">
                            <div class="dinv-wr-title">
                                {{ $it['title'] }}
                                @if($it['priority'])<span class="dinv-pill {{ ['high'=>'s-danger','medium'=>'s-warning','low'=>'s-gray'][$it['priority']] ?? 's-gray' }}">{{ ucfirst($it['priority']) }}</span>@endif
                            </div>
                            @if($it['rationale'])<div class="dinv-wr-rat">{{ $it['rationale'] }}</div>@endif
                            <div class="dinv-wr-deriv">
                                @if($it['derivation'] && !is_null($it['derivation']['target']))<span>Target level: <b>{{ $it['derivation']['target'] }}</b></span>@endif
                                @if($it['derivation'] && !is_null($it['derivation']['reorder_point']))<span>Reorder point: <b>{{ $it['derivation']['reorder_point'] }}</b></span>@endif
                                @if(!is_null($it['qty']))<span>Recommended qty: <b>{{ $it['qty'] }}</b></span>@endif
                                @if($it['value'])<span>Est. value: <b>{{ $it['value'] }}</b></span>@endif
                                @if($it['derivation'] && !is_null($it['derivation']['lead_time_days']))<span>Lead time: <b>{{ $it['derivation']['lead_time_days'] }}d</b></span>@endif
                                @if($it['derivation'] && $it['derivation']['supplier'])<span>Supplier: <b>{{ $it['derivation']['supplier'] }}</b></span>@endif
                            </div>
                        </div>
                    @endforeach
                    <div class="dinv-note">{{ $wr['note'] }}</div>
                @endif
            </div>
        </details>

        {{-- ── What-If / Action Simulator ────────────────────────────────── --}}
        <details class="dinv-panel">
            <summary>
                <span>🔮 What-If · Action Simulator</span>
                <span class="dinv-pill s-warning">Simulated</span>
                <span class="dinv-chev">▶</span>
            </summary>
            <div class="dinv-body">
                @if(!($wi['available'] ?? false))
                    <div class="dinv-empty">{{ $wi['empty_reason'] ?? 'Evidence unavailable.' }}</div>
                @else
                    @php $def = $wi['scenarios'][$wi['default_horizon']]; @endphp
                    <div class="dinv-sim-banner">▲ SIMULATED <span class="dinv-sim-sub">— a deterministic projection from governed inputs, not a measured outcome.</span></div>
                    <div class="dinv-hz" id="dinv-hz">
                        @foreach($wi['horizons'] as $h)
                            <button type="button" class="{{ $h == $wi['default_horizon'] ? 'on' : '' }}" x-on:click="dinvHz({{ (int) $h }})">{{ $h }}-day</button>
                        @endforeach
                    </div>
                    <div class="dinv-sim-grid">
                        <div class="dinv-sim-card no">
                            <div class="lab">If nothing changes</div>
                            <div class="big" id="dinv-no-main">{{ $wi['has_revenue'] ? $money($def['lost_revenue_no']) : number_format($def['no_action']['lost_units']) . ' units' }} lost</div>
                            <div class="sub" id="dinv-no-sub">{{ number_format($def['no_action']['lost_units']) }} units over {{ $def['no_action']['days_out'] }} stockout days · runs out in ~{{ $def['stockout_in_days'] }}d</div>
                        </div>
                        <div class="dinv-sim-card act">
                            <div class="lab">If actioned now</div>
                            <div class="big" id="dinv-act-main">{{ $wi['has_revenue'] ? $money($def['lost_revenue_with']) : number_format($def['with_action']['lost_units']) . ' units' }} lost</div>
                            <div class="sub" id="dinv-act-sub">@if($def['with_action']['lead_known']){{ number_format($def['with_action']['lost_units']) }} units during the lead-time gap @else lead time unknown — the simulator does not assume the gap closes @endif</div>
                        </div>
                    </div>
                    <div class="dinv-sim-protect" id="dinv-protect">{{ $wi['has_revenue'] ? $money($def['protected_revenue']) : number_format($def['protected_units']) . ' units' }} protected by acting now</div>
                    <div class="dinv-sim-assum">
                        @foreach($wi['assumptions'] as $as)<div>{{ $as }}</div>@endforeach
                    </div>
                    @php $txf = $wi['transfer'] ?? null; @endphp
                    @if($txf && ($txf['available'] ?? false))
                        <div class="dinv-tr">
                            <div class="dinv-tr-head">🔁 Transfer alternative <span class="dinv-pill s-warning">Simulated</span></div>
                            <div class="dinv-tr-body">
                                <p class="dinv-tr-lead">Store <b>{{ $txf['best_store'] }}</b> holds <b>{{ number_format($txf['best_surplus']) }}</b> releasable units of this SKU — stock it carries above its own replenishment target.
                                    @if($txf['donor_count'] > 1)<span><b>{{ number_format($txf['total_surplus']) }}</b> units are releasable across {{ $txf['donor_count'] }} stores. </span>@endif
                                    A transfer arriving in ~{{ $txf['transfer_lead'] }} days — ahead of the {{ $txf['po_lead'] }}-day supplier lead — could protect <b>{{ $wi['has_revenue'] && $txf['protected_revenue'] !== null ? $money($txf['protected_revenue']) : number_format($txf['protected_units']) . ' units' }}</b> that the PO's lead-time gap would otherwise lose.
                                    @if(!$txf['fully_covered'])<span> The surplus does not fully close the gap — it reduces it.</span>@endif
                                </p>
                                <div class="dinv-sim-assum"><div>{{ $txf['assumption'] }}</div></div>
                            </div>
                        </div>
                    @endif
                    <script type="application/json" id="dinv-sim-data">@json(['scenarios' => $wi['scenarios'], 'has_revenue' => $wi['has_revenue'], 'currency' => $wi['currency'] ?? ''])</script>
                @endif
            </div>
        </details>

        {{-- ── Similar Incidents ─────────────────────────────────────────── --}}
        <details class="dinv-panel">
            <summary>
                <span>🔗 Similar Incidents</span>
                @if(($si['available'] ?? false))<span class="dinv-count">{{ count($si['items']) }}</span>@endif
                <span class="dinv-chev">▶</span>
            </summary>
            <div class="dinv-body">
                @if(!($si['available'] ?? false))
                    <div class="dinv-empty">{{ $si['empty_reason'] ?? 'Evidence unavailable.' }}</div>
                @else
                    @foreach($si['items'] as $it)
                        <div class="dinv-sim-inc">
                            <div class="t">{{ $it['title'] }}</div>
                            <div class="m">{{ $it['match'] }}</div>
                            <div class="r">
                                @if($it['resolved_at'])<span>Resolved <b>{{ $it['resolved_at'] }}</b></span>@endif
                                @if($it['outcome'])<span>Outcome: <b>{{ $it['outcome'] }}</b></span>@endif
                                @if($it['recovery'])<span>Recovery: <b>{{ $it['recovery'] }}</b></span>@endif
                                @if($it['root_cause'])<span>Root cause: <b>{{ $it['root_cause'] }}</b></span>@endif
                            </div>
                            @if($it['playbook'])
                                <div class="pb">{{ $it['playbook']['done'] ? '✓ Worked last time' : 'Tried last time' }} — <b>{{ $it['playbook']['kind'] }}</b>: {{ $it['playbook']['title'] }}</div>
                            @endif
                        </div>
                    @endforeach
                    <div class="dinv-note">{{ $si['note'] }}</div>
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
                                @php $ct = $cf['causal']; @endphp
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
                    @php $caveats = $this->dataHealthCaveats(); @endphp
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
                        <button type="button" class="dinv-chip dinv-chip-on" x-on:click="dinvFilter('direction','all',$el)">All</button>
                        @foreach($ev['facets']['directions'] as $d)
                            <button type="button" class="dinv-chip" x-on:click="dinvFilter('direction',{{ \Illuminate\Support\Js::from($d) }},$el)">{{ ucfirst($d) }}</button>
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
                            @if(!is_null($imp['ai_estimate']))<div class="dinv-ev-src" style="margin-top:.25rem">AI estimate (not used in calculations): {{ $money($imp['ai_estimate']) }}</div>@endif
                        </div>
                        <div class="dinv-impact-card meas">
                            <div class="lab">Measured recovery</div>
                            @if(!empty($imp['measured']))
                                @php $ms = $imp['measured']; @endphp
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
                        @php $mx = max(array_column($imp['breakdown'], 'amount')) ?: 1; @endphp
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

        {{-- ── W9 (WP9.6) Data lineage ───────────────────────────────────── --}}
        @php $lin = $this->dataLineage(); @endphp
        <details class="dinv-panel">
            <summary>
                <span>🧬 Data lineage</span>
                @if(count($lin['batches']))<span class="dinv-count">{{ count($lin['batches']) }} batch(es)@if(count($lin['caveats'])) · {{ count($lin['caveats']) }} caveat(s)@endif</span>@endif
                <span class="dinv-chev">▶</span>
            </summary>
            <div class="dinv-body">
                @if(empty($lin['batches']))
                    <div class="dinv-empty">No loaded rows for these SKUs between {{ $lin['window'][0] }} and {{ $lin['window'][1] }} carry a batch reference.</div>
                @else
                    <div class="dinv-ev-src" style="margin-bottom:.5rem">Rows for {{ implode(', ', array_slice($lin['skus'], 0, 6)) }}@if(count($lin['skus']) > 6) and {{ count($lin['skus']) - 6 }} more @endif between {{ $lin['window'][0] }} and {{ $lin['window'][1] }} came from:</div>
                    <table class="dinv-ev-table"><tbody>
                        @foreach($lin['batches'] as $b)
                            <tr>
                                <td>
                                    <div class="dinv-ev-label">#{{ $b['id'] }} · {{ $b['file'] }}</div>
                                    <div class="dinv-ev-src">
                                        {{ ucfirst($b['source']) }} · loaded {{ \App\Support\Tenancy\TenantClock::display($b['loaded'])?->format('M j, Y H:i') }}
                                        @foreach($b['datasets'] as $label => $d) · {{ $label }}: {{ number_format($d['rows']) }} row(s), {{ $d['from'] }}@if($d['to'] !== $d['from'])–{{ $d['to'] }}@endif @endforeach
                                    </div>
                                    @if($b['flags'])<div class="dinv-ev-src" style="color:var(--ax-warn,#b45309)">⚠ {{ implode(' · ', $b['flags']) }}</div>@endif
                                </td>
                                <td class="dinv-ev-val">
                                    @if($b['state'])<span class="dinv-pill {{ match($b['state']) { 'green' => 's-success', 'amber' => 's-warning', 'red' => 's-danger', default => '' } }}">{{ strtoupper($b['state']) }}</span>@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody></table>
                    @if(count($lin['caveats']))
                        <div class="dinv-explain" style="margin-top:.75rem"><b>Data caveats:</b>
                            @foreach($lin['caveats'] as $c)<div style="margin-top:.2rem">• {{ $c }}</div>@endforeach
                        </div>
                    @endif
                @endif
            </div>
        </details>
    </div>

    <script>
    (function () {
        function esc(s){ var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; }
        function detailMap(){ try { return JSON.parse(document.getElementById('dinv-fish-detail').textContent) || {}; } catch (e) { return {}; } }
        var dirColor = { supports: '#b91c1c', contradicts: '#15803d', neutral: '#6b7280' };
        function kv(k, v){ return '<div class="dinv-drawer-kv"><span>' + k + '</span><b>' + v + '</b></div>'; }

        window.dinvNode = function (id) {
            var d = detailMap()[id];
            if (!d) return;
            document.querySelectorAll('.dinv-fish-node').forEach(function (g) { g.classList.remove('sel'); });
            var g = document.querySelector('.dinv-fish-node[data-id="' + id + '"]');
            if (g) g.classList.add('sel');

            document.getElementById('dinv-drawer-title').innerHTML =
                (d.sku ? '<b>' + esc(d.sku) + '</b> · ' : '') + esc(d.description || 'Signal');

            var rows = [];
            if (d.confidence_label) rows.push(kv('Confidence', esc(d.confidence_label)));
            if (d.gate_label) rows.push(kv('Recommended', esc(d.gate_label)));
            if (d.impact != null) rows.push(kv('Value at risk (est.)', esc(d.impact)));
            if (d.store_id != null) rows.push(kv('Store', '#' + esc(d.store_id)));
            if (d.lifecycle) rows.push(kv('Status', esc(d.lifecycle)));

            var ev = d.evidence || [];
            var evHtml = ev.length ? ev.map(function (e) {
                return '<div class="dinv-drawer-ev"><span class="dot" style="background:' + (dirColor[e.direction] || '#6b7280') + '"></span>' + esc(e.label) + ' <b>' + esc(e.value) + '</b></div>';
            }).join('') : '<div class="dinv-drawer-ev muted">No evidence rows collected for this signal yet.</div>';

            document.getElementById('dinv-drawer-body').innerHTML = rows.join('') + '<div class="dinv-drawer-evh">Evidence</div>' + evHtml;
            document.getElementById('dinv-fish-drawer').hidden = false;
        };

        window.dinvNodeClose = function () {
            var dr = document.getElementById('dinv-fish-drawer');
            if (dr) dr.hidden = true;
            document.querySelectorAll('.dinv-fish-node').forEach(function (g) { g.classList.remove('sel'); });
        };

        function dinvSetText(id, t) { var e = document.getElementById(id); if (e) e.textContent = t; }
        window.dinvHz = function (h) {
            var el = document.getElementById('dinv-sim-data');
            if (!el) return;
            var d; try { d = JSON.parse(el.textContent); } catch (e) { return; }
            var s = (d.scenarios || {})[h];
            if (!s) return;
            document.querySelectorAll('#dinv-hz button').forEach(function (b) { b.classList.toggle('on', b.textContent === (h + '-day')); });
            function money(n) { return (d.currency || '') + Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 0 }); }
            function units(n) { return Number(n || 0).toLocaleString() + ' units'; }
            dinvSetText('dinv-no-main', (d.has_revenue ? money(s.lost_revenue_no) : units(s.no_action.lost_units)) + ' lost');
            dinvSetText('dinv-act-main', (d.has_revenue ? money(s.lost_revenue_with) : units(s.with_action.lost_units)) + ' lost');
            dinvSetText('dinv-protect', (d.has_revenue ? money(s.protected_revenue) : units(s.protected_units)) + ' protected by acting now');
            dinvSetText('dinv-no-sub', Number(s.no_action.lost_units).toLocaleString() + ' units over ' + s.no_action.days_out + ' stockout days · runs out in ~' + s.stockout_in_days + 'd');
            dinvSetText('dinv-act-sub', s.with_action.lead_known ? (Number(s.with_action.lost_units).toLocaleString() + ' units during the lead-time gap') : 'lead time unknown — the simulator does not assume the gap closes');
        };
        window.dinvFilter = function (kind, val, btn) {
            var group = btn.parentNode;
            group.querySelectorAll('.dinv-chip').forEach(function (b) { b.classList.remove('dinv-chip-on'); });
            btn.classList.add('dinv-chip-on');
            document.querySelectorAll('#dinv-evidence-rows [data-ev]').forEach(function (r) {
                r.style.display = (val === 'all' || r.getAttribute('data-' + kind) === val) ? '' : 'none';
            });
        };

        // Keyboard access: Enter/Space on a focused fishbone node opens its detail.
        document.addEventListener('keydown', function (e) {
            var t = e.target;
            if ((e.key === 'Enter' || e.key === ' ') && t && t.classList && t.classList.contains('dinv-fish-node')) {
                e.preventDefault();
                window.dinvNode(t.getAttribute('data-id'));
            }
        });
    })();
    </script>
    @endif

</div>

</x-filament-panels::page>
