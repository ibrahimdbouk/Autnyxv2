<x-filament-panels::page>
<style>
/* ── SPLIT LAYOUT ──────────────────────────────────────────────────────────── */
.invs-wrap {
    display: flex;
    height: calc(100vh - 4rem);
    overflow: hidden;
    margin: -1.5rem -1.5rem 0;
    background: var(--ax-panel);
}
.invs-list-pane {
    display: flex;
    flex-direction: column;
    width: 100%;
    overflow: hidden;
    transition: width .25s ease;
    background: var(--ax-bg);
    border-right: 1px solid var(--ax-line);
}
.invs-panel-open .invs-list-pane {
    width: 44%;
    min-width: 380px;
}
.invs-panel {
    width: 56%;
    min-width: 420px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    background: var(--ax-bg);
}

/* ── LIST HEADER ────────────────────────────────────────────────────────────── */
.invs-list-header {
    padding: 1.25rem 1.5rem .875rem;
    border-bottom: 1px solid var(--ax-line-2);
    flex-shrink: 0;
}
.invs-title {
    font-size: 1.375rem;
    font-weight: 700;
    color: var(--ax-ink);
    line-height: 1.2;
}
.invs-subtitle {
    font-size: .875rem;
    color: var(--ax-muted);
    margin-top: .125rem;
}

/* ── FILTERS ────────────────────────────────────────────────────────────────── */
.invs-filters {
    padding: .875rem 1.5rem;
    border-bottom: 1px solid var(--ax-line-2);
    flex-shrink: 0;
    background: var(--ax-bg);
}
.invs-search {
    width: 100%;
    padding: .5rem .875rem;
    border: 1px solid var(--ax-line);
    border-radius: .5rem;
    font-size: .875rem;
    color: var(--ax-ink);
    background: var(--ax-panel);
    margin-bottom: .625rem;
    outline: none;
    transition: border-color .15s;
}
.invs-search:focus { border-color: var(--ax-accent); background: var(--ax-bg); }
.invs-search::placeholder { color: var(--ax-faint); }
.invs-filter-row {
    display: flex;
    gap: .5rem;
    flex-wrap: wrap;
}
.invs-select {
    flex: 1;
    min-width: 100px;
    padding: .375rem .625rem;
    border: 1px solid var(--ax-line);
    border-radius: .375rem;
    font-size: .8125rem;
    color: var(--ax-text);
    background: var(--ax-bg);
    cursor: pointer;
    outline: none;
}
.invs-select:focus { border-color: var(--ax-accent); }

/* ── INVESTIGATION CARDS ────────────────────────────────────────────────────── */
.invs-cards {
    flex: 1;
    overflow-y: auto;
    padding: .375rem 0;
}
.invs-card {
    display: flex;
    align-items: center;
    gap: .875rem;
    padding: .875rem 1.5rem;
    border-bottom: 1px solid var(--ax-line-2);
    cursor: pointer;
    transition: background .1s;
    position: relative;
}
.invs-card:hover { background: var(--ax-panel); }
.invs-card-selected {
    background: var(--ax-accent-soft) !important;
    border-left: 3px solid var(--ax-accent);
    padding-left: calc(1.5rem - 3px);
}
.invs-card-icon {
    width: 2.5rem;
    height: 2.5rem;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.invs-card-body {
    flex: 1;
    min-width: 0;
}
.invs-card-entity {
    font-size: .9375rem;
    font-weight: 600;
    color: var(--ax-ink);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.invs-card-type {
    font-size: .8125rem;
    color: var(--ax-muted);
    margin-top: .0625rem;
}
.invs-card-desc {
    font-size: .8125rem;
    color: var(--ax-faint);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-top: .0625rem;
}
.invs-card-impact {
    text-align: right;
    flex-shrink: 0;
    min-width: 4.5rem;
}
.invs-impact-value {
    font-size: .9375rem;
    font-weight: 700;
    color: var(--ax-danger);
}
.invs-impact-label {
    font-size: .6875rem;
    color: var(--ax-faint);
    margin-top: .125rem;
}
.invs-card-meta {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: .25rem;
    flex-shrink: 0;
    min-width: 7rem;
}
.invs-sev {
    font-size: .6875rem;
    font-weight: 700;
    padding: .1875rem .5rem;
    border-radius: 9999px;
    letter-spacing: .04em;
}
.invs-sev-critical { background: var(--ax-danger-soft); color: var(--ax-danger-fg); }
.invs-sev-high     { background: var(--ax-warning-soft); color: var(--ax-warning-fg); }
.invs-sev-medium   { background: var(--ax-info-soft); color: var(--ax-info-fg); }
.invs-sev-low      { background: var(--ax-neutral-soft); color: var(--ax-neutral-fg); }
.invs-status {
    font-size: .6875rem;
    font-weight: 500;
    padding: .1875rem .5rem;
    border-radius: 9999px;
    white-space: nowrap;
}
.invs-status-open     { background: var(--ax-warning-soft); color: var(--ax-warning-fg); }
.invs-status-progress { background: var(--ax-info-soft); color: var(--ax-info-fg); }
.invs-status-resolved { background: var(--ax-success-soft); color: var(--ax-success-fg); }
.invs-status-closed   { background: var(--ax-neutral-soft); color: var(--ax-neutral-fg); }
.invs-time {
    font-size: .6875rem;
    color: var(--ax-faint);
}
.invs-card-chevron {
    font-size: 1.25rem;
    color: var(--ax-faint);
    flex-shrink: 0;
    margin-left: .25rem;
}
.invs-card-selected .invs-card-chevron { color: var(--ax-accent); }
.invs-empty {
    padding: 2rem 1.5rem;
    text-align: center;
    font-size: .9375rem;
    color: var(--ax-faint);
}

/* ── PAGINATION ─────────────────────────────────────────────────────────────── */
.invs-pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: .75rem 1.5rem;
    border-top: 1px solid var(--ax-line-2);
    background: var(--ax-bg);
    flex-shrink: 0;
}
.invs-page-btn {
    width: 2rem;
    height: 2rem;
    border: 1px solid var(--ax-line);
    border-radius: .375rem;
    font-size: 1rem;
    cursor: pointer;
    background: var(--ax-bg);
    color: var(--ax-text);
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all .15s;
}
.invs-page-btn:hover:not(.invs-page-disabled) { background: var(--ax-panel); }
.invs-page-disabled { color: var(--ax-faint); cursor: not-allowed; }
.invs-page-info { font-size: .875rem; color: var(--ax-text); font-weight: 500; }
.invs-page-count { font-size: .8125rem; color: var(--ax-faint); }

/* ── QUICK PANEL HEADER ─────────────────────────────────────────────────────── */
.invs-panel-header {
    padding: 1rem 1.25rem 0;
    border-bottom: 1px solid var(--ax-line);
    flex-shrink: 0;
    background: var(--ax-bg);
}
.invs-panel-title-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: .75rem;
}
.invs-panel-id {
    font-size: .875rem;
    font-weight: 700;
    color: var(--ax-ink);
}
.invs-panel-sev-badge {
    display: inline-flex;
    align-items: center;
    gap: .25rem;
    font-size: .6875rem;
    font-weight: 700;
    padding: .1875rem .5rem;
    border-radius: 9999px;
    letter-spacing: .04em;
    margin-top: .25rem;
}
.invs-panel-actions {
    display: flex;
    align-items: center;
    gap: .5rem;
}
.invs-expand-btn, .invs-close-btn {
    width: 2rem;
    height: 2rem;
    border-radius: .375rem;
    border: 1px solid var(--ax-line);
    background: var(--ax-bg);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: var(--ax-muted);
    font-size: .875rem;
    text-decoration: none;
    transition: all .15s;
}
.invs-expand-btn:hover, .invs-close-btn:hover { background: var(--ax-panel); color: var(--ax-ink); }
.invs-tabs {
    display: flex;
    gap: 0;
    margin-top: .25rem;
}
.invs-tab {
    padding: .625rem 1rem;
    font-size: .875rem;
    font-weight: 500;
    color: var(--ax-muted);
    border-bottom: 2px solid transparent;
    cursor: pointer;
    text-decoration: none;
    transition: all .15s;
}
.invs-tab:hover { color: var(--ax-text); }
.invs-tab-active { color: var(--ax-accent); border-bottom-color: var(--ax-accent); }

/* ── QUICK PANEL BODY ────────────────────────────────────────────────────────── */
.invs-panel-body {
    flex: 1;
    overflow-y: auto;
    padding: 1.25rem;
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

/* ── FACT SECTION ────────────────────────────────────────────────────────────── */
.invs-fact-section {
    background: var(--ax-bg);
    border: 1px solid var(--ax-line);
    border-radius: .625rem;
    padding: 1rem;
}
.invs-fact-header {
    display: flex;
    align-items: center;
    gap: .875rem;
    margin-bottom: .625rem;
}
.invs-fact-entity {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--ax-ink);
}
.invs-fact-type {
    font-size: .875rem;
    color: var(--ax-muted);
    margin-top: .0625rem;
}
.invs-fact-desc {
    font-size: .875rem;
    color: var(--ax-text);
    line-height: 1.5;
    margin: 0;
}

/* ── KPI ROW ────────────────────────────────────────────────────────────────── */
.invs-kpi-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: .625rem;
}
.invs-kpi-card {
    background: var(--ax-panel);
    border: 1px solid var(--ax-line);
    border-radius: .5rem;
    padding: .75rem;
}
.invs-kpi-label {
    font-size: .6875rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: var(--ax-faint);
    margin-bottom: .25rem;
}
.invs-kpi-value {
    font-size: 1.375rem;
    font-weight: 800;
    line-height: 1.1;
}
.invs-kpi-red   { color: var(--ax-danger); }
.invs-kpi-green { color: var(--ax-success); }
.invs-kpi-sub {
    font-size: .75rem;
    color: var(--ax-muted);
    margin-top: .25rem;
    line-height: 1.3;
}
.invs-evidence-dots {
    font-size: 1rem;
    letter-spacing: .125rem;
    margin-bottom: .125rem;
}

/* ── WHAT WE THINK ───────────────────────────────────────────────────────────── */
.invs-inference-section {
    background: var(--ax-panel);
    border: 1px solid var(--ax-line);
    border-radius: .625rem;
    padding: .875rem 1rem;
}
.invs-section-label {
    font-size: .6875rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: var(--ax-faint);
    margin-bottom: .5rem;
    display: flex;
    align-items: center;
    gap: .5rem;
}
.invs-inference-marker {
    display: inline-flex;
    padding: .125rem .4rem;
    background: var(--ax-warning-soft);
    color: var(--ax-warning-fg);
    border-radius: .25rem;
    font-size: .625rem;
    font-weight: 700;
    letter-spacing: .05em;
}
.invs-inference-text {
    font-size: .9375rem;
    color: var(--ax-ink);
    line-height: 1.6;
    font-weight: 500;
    margin: 0 0 .625rem;
}
.invs-evidence-label {
    display: inline-flex;
    align-items: center;
    gap: .375rem;
    font-size: .8125rem;
    font-weight: 600;
    padding: .25rem .625rem;
    border-radius: 9999px;
}
.invs-ev-strong      { background: var(--ax-success-soft); color: var(--ax-success-fg); }
.invs-ev-moderate    { background: var(--ax-warning-soft); color: var(--ax-warning-fg); }
.invs-ev-insufficient{ background: var(--ax-neutral-soft); color: var(--ax-muted); }

/* ── CONFIDENCE-GATED ACTION ─────────────────────────────────────────────────── */
.invs-action-section {
    border: 1px solid var(--ax-line);
    border-radius: .625rem;
    padding: .875rem 1rem;
    background: var(--ax-bg);
}
.invs-action-title {
    font-size: 1.0625rem;
    font-weight: 700;
    color: var(--ax-ink);
    margin: .375rem 0;
}
.invs-action-meta {
    font-size: .8125rem;
    color: var(--ax-muted);
    margin-top: .25rem;
}
.invs-action-note {
    font-size: .8125rem;
    color: var(--ax-warning-fg);
    background: var(--ax-warning-soft);
    border-radius: .375rem;
    padding: .375rem .625rem;
    margin: .5rem 0;
}
.invs-action-btns {
    display: flex;
    gap: .5rem;
    margin-top: .75rem;
}
.invs-incomplete-banner {
    background: var(--ax-warning-soft);
    border: 1px solid var(--ax-warning-soft);
    border-radius: .5rem;
    padding: .75rem;
}
.invs-btn-primary {
    padding: .5rem 1rem;
    background: var(--ax-accent);
    color: var(--ax-accent-fg);
    border: none;
    border-radius: .375rem;
    font-size: .875rem;
    font-weight: 600;
    cursor: pointer;
    transition: background .15s;
    text-decoration: none;
    display: inline-block;
}
.invs-btn-primary:hover { background: var(--ax-accent-strong); }
.invs-btn-secondary {
    padding: .5rem 1rem;
    background: var(--ax-bg);
    color: var(--ax-accent);
    border: 1.5px solid var(--ax-accent);
    border-radius: .375rem;
    font-size: .875rem;
    font-weight: 600;
    cursor: pointer;
    transition: all .15s;
    text-decoration: none;
    display: inline-block;
}
.invs-btn-secondary:hover { background: var(--ax-accent-soft); }
.invs-btn-ghost {
    padding: .5rem 1rem;
    background: transparent;
    color: var(--ax-muted);
    border: 1px solid var(--ax-line);
    border-radius: .375rem;
    font-size: .875rem;
    font-weight: 500;
    cursor: pointer;
    transition: all .15s;
}
.invs-btn-ghost:hover { background: var(--ax-panel); color: var(--ax-text); }
.invs-btn-warning {
    padding: .5rem 1rem;
    background: var(--ax-warning-soft);
    color: var(--ax-warning-fg);
    border: 1px solid var(--ax-warning-soft);
    border-radius: .375rem;
    font-size: .875rem;
    font-weight: 600;
    cursor: pointer;
    transition: all .15s;
    text-decoration: none;
    display: inline-block;
}
.invs-btn-warning:hover { filter: brightness(.97); }

/* ── EVIDENCE GRID ───────────────────────────────────────────────────────────── */
.invs-evidence-section {
    border: 1px solid var(--ax-line);
    border-radius: .625rem;
    padding: .875rem 1rem;
    background: var(--ax-bg);
}
.invs-section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: .75rem;
}
.invs-link {
    font-size: .8125rem;
    color: var(--ax-accent);
    text-decoration: none;
    font-weight: 500;
}
.invs-link:hover { text-decoration: underline; }
.invs-evidence-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: .5rem;
}
.invs-ev-card {
    background: var(--ax-panel);
    border: 1px solid var(--ax-line-2);
    border-radius: .5rem;
    padding: .625rem .75rem;
}
.invs-ev-card-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: .375rem;
    margin-bottom: .25rem;
}
.invs-ev-label {
    font-size: .8125rem;
    font-weight: 600;
    color: var(--ax-ink);
    line-height: 1.3;
}
.invs-ev-strength {
    font-size: .625rem;
    font-weight: 700;
    padding: .125rem .375rem;
    border-radius: 9999px;
    flex-shrink: 0;
    white-space: nowrap;
}
.invs-ev-tag-strong   { background: var(--ax-success-soft); color: var(--ax-success-fg); }
.invs-ev-tag-moderate { background: var(--ax-warning-soft); color: var(--ax-warning-fg); }
.invs-ev-tag-weak     { background: var(--ax-neutral-soft); color: var(--ax-muted); }
.invs-ev-value {
    font-size: .9375rem;
    font-weight: 700;
    color: var(--ax-text);
}
.invs-ev-source {
    font-size: .6875rem;
    color: var(--ax-faint);
    margin-top: .125rem;
}

/* ── ASSIGNMENT ──────────────────────────────────────────────────────────────── */
.invs-assignment-section {
    border: 1px solid var(--ax-line);
    border-radius: .625rem;
    padding: .875rem 1rem;
    background: var(--ax-bg);
}
.invs-assigned-state {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .75rem;
}
.invs-assigned-info {
    display: flex;
    align-items: center;
    gap: .625rem;
}
.invs-assigned-dot {
    width: .5rem;
    height: .5rem;
    border-radius: 50%;
    display: inline-block;
    flex-shrink: 0;
}
.invs-assigned-name {
    font-size: .9375rem;
    font-weight: 600;
    color: var(--ax-ink);
}
.invs-unassigned-state {
    display: flex;
    align-items: center;
    gap: .75rem;
    flex-wrap: wrap;
}
.invs-status-pill {
    display: inline-flex;
    align-items: center;
    font-size: .75rem;
    font-weight: 700;
    padding: .25rem .75rem;
    border-radius: 9999px;
}
.invs-sla-label {
    font-size: .8125rem;
    font-weight: 500;
    padding: .25rem .625rem;
    border-radius: .375rem;
}
.invs-sla-ok      { background: var(--ax-success-soft); color: var(--ax-success-fg); }
.invs-sla-overdue { background: var(--ax-danger-soft); color: var(--ax-danger-fg); }
.invs-assign-btns { display: flex; gap: .5rem; flex-wrap: wrap; }

/* ── PANEL META + EXPAND BUTTON ──────────────────────────────────────────────── */
.invs-panel-meta {
    font-size: .75rem;
    color: var(--ax-faint);
    padding: .375rem 0;
}
.invs-expand-full {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: .375rem;
    width: 100%;
    padding: .875rem;
    background: var(--ax-panel);
    border: 1.5px solid var(--ax-line);
    border-radius: .625rem;
    font-size: .9375rem;
    font-weight: 600;
    color: var(--ax-text);
    text-decoration: none;
    cursor: pointer;
    transition: all .15s;
    margin-top: .25rem;
}
.invs-expand-full:hover { background: var(--ax-accent-soft); border-color: var(--ax-accent-soft-border); color: var(--ax-accent-strong); }

/* ── RESPONSIVE ──────────────────────────────────────────────────────────────── */
@media (max-width: 900px) {
    .invs-wrap { flex-direction: column; height: auto; overflow: visible; }
    .invs-list-pane { width: 100% !important; min-width: 0; height: 50vh; }
    .invs-panel { width: 100%; min-width: 0; border-left: none; border-top: 1px solid var(--ax-line); }
    .invs-kpi-row { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 640px) {
    .invs-kpi-row { grid-template-columns: 1fr; }
    .invs-evidence-grid { grid-template-columns: 1fr; }
    .invs-card-meta { min-width: 0; }
}

{{-- Dark mode is handled entirely by the design tokens above — every color
     resolves from an --ax-* var that flips under .dark. No bespoke overrides. --}}
</style>

@php
$investigations = $this->getInvestigations();
$openCount      = $this->getOpenCount();
$selected       = $this->getSelectedInvestigation();

// Derived panel data
$invId          = null;
$primaryEntity  = null;
$primaryAnomaly = null;
$evidenceStrengthLabel = 'Insufficient';
$evidenceDots   = 1;
$confidenceGate = 'low';
$slaLabel       = null;
$slaOverdue     = false;
$topEvidence    = collect();
$expandUrl      = '#';

if ($selected) {
    $invId = sprintf('INV-%d-%06d',
        $selected->opened_at ? (int) $selected->opened_at->format('Y') : (int) date('Y'),
        $selected->id
    );
    $primaryAnomaly = $selected->anomalies
        ->sortByDesc(fn($a) => ['low'=>1,'medium'=>2,'high'=>3][$a->severity] ?? 1)
        ->first();
    $primaryEntity = $selected->primaryStore?->name
        ?? ($selected->primary_sku ? "SKU {$selected->primary_sku}" : ($selected->title ?? "Investigation #{$selected->id}"));

    // Evidence strength
    $supportingEvidence = $selected->evidence->where('direction', 'supports');
    $strongSupporting   = $supportingEvidence->where('strength', 'strong')->count();
    $evidenceStrengthLabel = match(true) {
        $strongSupporting >= 3 || ($strongSupporting >= 1 && $supportingEvidence->count() >= 3) => 'Strong',
        $strongSupporting >= 1 || $supportingEvidence->count() >= 2 => 'Moderate',
        default => 'Insufficient',
    };
    $evidenceDots = match($evidenceStrengthLabel) { 'Strong' => 5, 'Moderate' => 3, default => 1 };

    // Confidence gate
    $confidenceGate = match($selected->ai_confidence) {
        'established' => 'high',
        'probable'    => 'medium',
        default       => 'low',
    };

    // SLA
    $slaHours    = match($selected->priority) { 'critical'=>2, 'high'=>4, 'medium'=>8, default=>24 };
    $slaDeadline = $selected->opened_at?->copy()->addHours($slaHours);
    $slaOverdue  = $slaDeadline && now()->isAfter($slaDeadline);
    if ($slaDeadline && !$selected->assignedTeam && !$selected->assignedUser) {
        if ($slaOverdue) {
            $slaLabel = 'Assignment overdue';
        } else {
            $diffMin = now()->diffInMinutes($slaDeadline);
            $slaLabel = floor($diffMin/60).'h '.($diffMin%60).'m remaining';
        }
    }

    // Top evidence
    $topEvidence = $selected->evidence
        ->sortByDesc(fn($e) => ['strong'=>3,'moderate'=>2,'weak'=>1][$e->strength] ?? 0)
        ->take(4);

    $expandUrl = \App\Filament\Resources\InvestigationResource::getUrl('investigate', ['record' => $selected->id]);
}
@endphp

<div class="invs-wrap {{ $selectedInvestigationId ? 'invs-panel-open' : '' }}">

    {{-- ── LEFT: Investigation List ──────────────────────────────────────── --}}
    <div class="invs-list-pane">

        {{-- Header --}}
        <div class="invs-list-header">
            <h1 class="invs-title">Investigations</h1>
            <p class="invs-subtitle">{{ number_format($openCount) }} open investigations</p>
        </div>

        {{-- Search & Filters --}}
        <div class="invs-filters">
            <input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="Filter investigations..."
                class="invs-search"
            />
            <div class="invs-filter-row">
                <select wire:model.live="priorityFilter" class="invs-select">
                    <option value="">Severity</option>
                    <option value="high_critical">High + Critical</option>
                    <option value="critical">Critical</option>
                    <option value="high">High</option>
                    <option value="medium">Medium</option>
                    <option value="low">Low</option>
                </select>
                <select wire:model.live="statusFilter" class="invs-select">
                    <option value="open">Open</option>
                    <option value="in_progress">In Progress</option>
                    <option value="resolved">Resolved</option>
                    <option value="closed">Closed</option>
                    <option value="">All Statuses</option>
                </select>
                <select wire:model.live="sortField" class="invs-select">
                    <option value="opened_at">Sort: Newest</option>
                    <option value="priority">Sort: Priority</option>
                    <option value="revenue_at_risk">Sort: Impact</option>
                    <option value="status">Sort: Status</option>
                </select>
            </div>
        </div>

        {{-- Feature 7 — Bulk action toolbar --}}
        {{-- NOTE: use $this->selected (the Livewire id array). The blade-local
             $selected above holds the side-panel Investigation model (or null). --}}
        @php $selCount = count($this->selected); $matchingCount = $this->getMatchingCount(); $effCount = $this->selectAllMatching ? $matchingCount : $selCount; @endphp
        <style>
            .invs-bulkbar { display:flex; flex-wrap:wrap; align-items:center; gap:.5rem; background:var(--ax-accent-soft); border:1px solid var(--ax-accent-soft-border); border-radius:.6rem; padding:.55rem .75rem; margin-bottom:.75rem; font-size:.78rem; }
            .invs-bulkbar .cnt { font-weight:700; color:var(--ax-accent-strong); }
            .invs-bulkbar select, .invs-bulkbar button { font-size:.75rem; border-radius:.4rem; border:1px solid var(--ax-accent-soft-border); padding:.25rem .5rem; background:var(--ax-bg); color:var(--ax-text); cursor:pointer; }
            .invs-bulkbar button:hover { background:var(--ax-accent-soft); }
            .invs-bulkbar .danger { border-color:var(--ax-danger); color:var(--ax-danger); }
            .invs-bulkbar .allmatch { color:var(--ax-accent-strong); text-decoration:underline; cursor:pointer; background:none; border:none; }
            .invs-check { display:flex; align-items:center; padding-right:.25rem; }
            .invs-check input { width:1rem; height:1rem; cursor:pointer; }
        </style>
        @if($effCount > 0)
        <div class="invs-bulkbar">
            <span class="cnt">{{ number_format($effCount) }} selected</span>
            @if(! $this->selectAllMatching && $selCount > 0 && $matchingCount > $selCount)
                <button type="button" class="allmatch" wire:click="toggleSelectAllMatching">Select all {{ number_format($matchingCount) }} matching</button>
            @elseif($this->selectAllMatching)
                <span style="color:var(--ax-accent-strong);">All matching selected</span>
            @endif

            <button type="button" wire:click="bulkAssignToMe">Assign to me</button>

            <select wire:model="bulkTeamId">
                <option value="">Team…</option>
                @foreach($this->getAvailableTeams() as $tid => $tname)
                    <option value="{{ $tid }}">{{ $tname }}</option>
                @endforeach
            </select>
            <button type="button" wire:click="bulkReassignTeam">Reassign</button>

            <select wire:model="bulkPriority">
                <option value="">Priority…</option>
                <option value="critical">Critical</option>
                <option value="high">High</option>
                <option value="medium">Medium</option>
                <option value="low">Low</option>
            </select>
            <button type="button" wire:click="bulkChangePriority">Set priority</button>

            <button type="button" wire:click="bulkSnooze">Snooze 7d</button>
            <button type="button" class="danger" wire:click="bulkDismiss" wire:confirm="Dismiss {{ number_format($effCount) }} investigation(s)?">Dismiss</button>
            <button type="button" wire:click="bulkExport">Export CSV</button>
            <button type="button" wire:click="clearSelection">Clear</button>
        </div>
        @endif

        {{-- Cards --}}
        <div class="invs-cards">
            @forelse($investigations as $inv)
            @php
                $isSelected   = $inv->id === $this->selectedInvestigationId;
                $pAnomaly     = $inv->anomalies->first();
                $entityName   = $inv->primaryStore?->name
                    ?? ($inv->primary_sku ? "SKU-{$inv->primary_sku}" : ($inv->title ?? "Investigation #{$inv->id}"));
                $ruleLabel    = $pAnomaly?->getRuleLabel() ?? 'Multiple Anomalies';
                $sevClass     = 'invs-sev-'.($inv->priority ?? 'low');
                $statusLabel  = match($inv->status) {
                    'open'        => 'Needs Assignment',
                    'in_progress' => 'In Progress',
                    'resolved'    => 'Resolved',
                    'closed'      => 'Closed',
                    default       => ucfirst($inv->status),
                };
                $statusClass  = match($inv->status) {
                    'in_progress' => 'invs-status-progress',
                    'resolved'    => 'invs-status-resolved',
                    'closed'      => 'invs-status-closed',
                    default       => 'invs-status-open',
                };
                $iconBg    = match($inv->priority) {
                    'critical','high' => 'var(--ax-danger-soft)',
                    'medium'          => 'var(--ax-warning-soft)',
                    default           => 'var(--ax-info-soft)',
                };
                $iconColor = match($inv->priority) {
                    'critical','high' => 'var(--ax-danger)',
                    'medium'          => 'var(--ax-warning)',
                    default           => 'var(--ax-info)',
                };
            @endphp
            <div
                class="invs-card {{ $isSelected ? 'invs-card-selected' : '' }}"
                wire:click="selectInvestigation({{ $inv->id }})"
                wire:key="inv-{{ $inv->id }}"
            >
                <label class="invs-check" wire:click.stop>
                    <input type="checkbox"
                        wire:click.stop="toggleSelected({{ $inv->id }})"
                        @checked($this->selectAllMatching || in_array($inv->id, $this->selected))
                    />
                </label>

                <div class="invs-card-icon" style="background:{{ $iconBg }}">
                    <svg style="width:1.125rem;height:1.125rem;color:{{ $iconColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                    </svg>
                </div>

                <div class="invs-card-body">
                    <div class="invs-card-entity">{{ $entityName }}</div>
                    <div class="invs-card-type">{{ $ruleLabel }}</div>
                    @if($inv->description)
                    <div class="invs-card-desc">{{ \Illuminate\Support\Str::limit($inv->description, 65) }}</div>
                    @endif
                </div>

                <div class="invs-card-impact">
                    @if($inv->revenue_at_risk)
                    <div class="invs-impact-value">
                        {{ \App\Support\Money::prefix(\Filament\Facades\Filament::getTenant()?->currency) }}{{ $inv->revenue_at_risk >= 1000000
                            ? number_format($inv->revenue_at_risk/1000000, 1).'M'
                            : number_format($inv->revenue_at_risk/1000, 0).'K' }}
                    </div>
                    <div class="invs-impact-label">at risk</div>
                    @else
                    <div class="invs-impact-value" style="color:var(--ax-faint)">—</div>
                    @endif
                </div>

                <div class="invs-card-meta">
                    <span class="invs-sev {{ $sevClass }}">{{ strtoupper($inv->priority ?? 'LOW') }}</span>
                    <span class="invs-status {{ $statusClass }}">{{ $statusLabel }}</span>
                    <div class="invs-time">{{ $inv->opened_at?->diffForHumans() }}</div>
                </div>

                <div class="invs-card-chevron">›</div>
            </div>
            @empty
            <div class="invs-empty">No investigations match your filters.</div>
            @endforelse
        </div>

        {{-- Pagination --}}
        @if($investigations->hasPages())
        <div class="invs-pagination">
            @if($investigations->onFirstPage())
                <span class="invs-page-btn invs-page-disabled">‹</span>
            @else
                <button class="invs-page-btn" wire:click="$set('currentPage', {{ $investigations->currentPage() - 1 }})">‹</button>
            @endif
            <span class="invs-page-count">{{ number_format($investigations->total()) }} total</span>
            <span class="invs-page-info">Page {{ $investigations->currentPage() }} / {{ $investigations->lastPage() }}</span>
            @if($investigations->hasMorePages())
                <button class="invs-page-btn" wire:click="$set('currentPage', {{ $investigations->currentPage() + 1 }})">›</button>
            @else
                <span class="invs-page-btn invs-page-disabled">›</span>
            @endif
        </div>
        @endif

    </div>{{-- end .invs-list-pane --}}

    {{-- ── RIGHT: Quick Investigation Panel ──────────────────────────── --}}
    @if($selectedInvestigationId && $selected)
    <div class="invs-panel" wire:key="panel-{{ $selectedInvestigationId }}">

        {{-- Panel Header --}}
        <div class="invs-panel-header">
            <div class="invs-panel-title-row">
                <div>
                    <div class="invs-panel-id">Investigation: {{ $invId }}</div>
                    @php
                        $sevStyle = match($selected->priority) {
                            'critical' => 'background:var(--ax-danger-soft);color:var(--ax-danger-fg)',
                            'high'     => 'background:var(--ax-warning-soft);color:var(--ax-warning-fg)',
                            'medium'   => 'background:var(--ax-info-soft);color:var(--ax-info-fg)',
                            default    => 'background:var(--ax-neutral-soft);color:var(--ax-neutral-fg)',
                        };
                    @endphp
                    <span class="invs-panel-sev-badge" style="{{ $sevStyle }}">
                        &#9679; {{ strtoupper($selected->priority ?? 'low') }}
                    </span>
                </div>
                <div class="invs-panel-actions">
                    <a href="{{ $expandUrl }}" class="invs-expand-btn" title="Expand to full investigation">
                        <svg style="width:1rem;height:1rem" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"/>
                        </svg>
                    </a>
                    <button wire:click="closePanel" class="invs-close-btn">&#x2715;</button>
                </div>
            </div>
            <div class="invs-tabs">
                <span class="invs-tab invs-tab-active">Quick View</span>
                <a href="{{ $expandUrl }}" class="invs-tab">Full Investigation &#x2192;</a>
            </div>
        </div>

        {{-- Panel Body --}}
        <div class="invs-panel-body">

            {{-- FACT --}}
            <div class="invs-fact-section">
                <div class="invs-fact-header">
                    @php
                        $factIconBg    = match($selected->priority) { 'critical','high' => 'var(--ax-danger-soft)', 'medium' => 'var(--ax-warning-soft)', default => 'var(--ax-info-soft)' };
                        $factIconColor = match($selected->priority) { 'critical','high' => 'var(--ax-danger)', 'medium' => 'var(--ax-warning)', default => 'var(--ax-info)' };
                    @endphp
                    <div style="width:3rem;height:3rem;border-radius:50%;background:{{ $factIconBg }};display:flex;align-items:center;justify-content:center;flex-shrink:0">
                        <svg style="width:1.5rem;height:1.5rem;color:{{ $factIconColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                        </svg>
                    </div>
                    <div>
                        <div class="invs-fact-entity">{{ $primaryEntity }}</div>
                        <div class="invs-fact-type">{{ $primaryAnomaly?->getRuleLabel() ?? 'Multiple Anomaly Types' }}</div>
                    </div>
                </div>
                @if($selected->description)
                <p class="invs-fact-desc">{{ $selected->description }}</p>
                @elseif($primaryAnomaly?->description)
                <p class="invs-fact-desc">{{ $primaryAnomaly->description }}</p>
                @endif
            </div>

            {{-- KPI Row --}}
            <div class="invs-kpi-row">
                <div class="invs-kpi-card">
                    <div class="invs-kpi-label">Revenue at Risk</div>
                    <div class="invs-kpi-value invs-kpi-red">
                        @if($selected->revenue_at_risk)
                            {{ \App\Support\Money::prefix(\Filament\Facades\Filament::getTenant()?->currency) }}{{ $selected->revenue_at_risk >= 1000000
                                ? number_format($selected->revenue_at_risk/1000000,1).'M'
                                : number_format($selected->revenue_at_risk/1000,0).'K' }}
                        @else —
                        @endif
                    </div>
                    <div class="invs-kpi-sub">
                        {{ $selected->anomaly_count }} anomal{{ $selected->anomaly_count === 1 ? 'y' : 'ies' }} linked
                    </div>
                </div>

                <div class="invs-kpi-card">
                    <div class="invs-kpi-label">Evidence Strength</div>
                    <div class="invs-evidence-dots">
                        @for($i=1; $i<=5; $i++)
                        <span style="color:{{ $i <= $evidenceDots ? 'var(--ax-success)' : 'var(--ax-line)' }}">&#9679;</span>
                        @endfor
                    </div>
                    <div class="invs-kpi-sub" style="font-weight:600;color:var(--ax-ink)">{{ $evidenceStrengthLabel }}</div>
                </div>

                <div class="invs-kpi-card">
                    <div class="invs-kpi-label">Cause Confidence</div>
                    <div class="invs-kpi-value invs-kpi-green">
                        {{ match($selected->ai_confidence) {
                            'established' => '90%+',
                            'probable'    => '~70%',
                            'suspected'   => '~40%',
                            default       => 'N/A',
                        } }}
                    </div>
                    <div class="invs-kpi-sub">{{ ucfirst($selected->ai_confidence ?? 'unknown') }}</div>
                </div>
            </div>

            {{-- WHAT WE THINK --}}
            @if($selected->ai_root_cause || $selected->ai_summary)
            <div class="invs-inference-section">
                <div class="invs-section-label">
                    <span class="invs-inference-marker">INFERENCE</span>
                    What we think
                </div>
                <p class="invs-inference-text">{{ $selected->ai_root_cause ?? $selected->ai_summary }}</p>
                <span class="invs-evidence-label {{ match($evidenceStrengthLabel) { 'Strong'=>'invs-ev-strong', 'Moderate'=>'invs-ev-moderate', default=>'invs-ev-insufficient' } }}">
                    {{ match($evidenceStrengthLabel) { 'Strong'=>'Strong evidence', 'Moderate'=>'Moderate evidence', default=>'Insufficient evidence' } }}
                </span>
            </div>
            @endif

            {{-- CONFIDENCE-GATED ACTION --}}
            <div class="invs-action-section">
                @if($confidenceGate === 'high')
                    <div class="invs-section-label">Recommended Next Step</div>
                    @if($selected->ai_recommended_action)
                    <div class="invs-action-title">{{ $selected->ai_recommended_action }}</div>
                    @endif
                    @if($selected->assignedTeam)
                    <div class="invs-action-meta">Recommended owner: {{ $selected->assignedTeam->name }}</div>
                    @endif
                    <div class="invs-action-meta">SLA: {{ match($selected->priority) { 'critical'=>'2h', 'high'=>'4h', 'medium'=>'8h', default=>'24h' } }} from detection</div>
                    <div class="invs-action-btns">
                        <button class="invs-btn-primary">Assign</button>
                        <button class="invs-btn-ghost">View Alternatives</button>
                    </div>

                @elseif($confidenceGate === 'medium')
                    <div class="invs-section-label">Next Best Step</div>
                    <div class="invs-action-title" style="font-size:.9375rem">
                        {{ $selected->ai_recommended_action ?? 'Gather additional evidence before taking action.' }}
                    </div>
                    <div class="invs-action-note">Moderate confidence — verify the root cause before assigning resources.</div>
                    <div class="invs-action-btns">
                        <a href="{{ $expandUrl }}" class="invs-btn-secondary">Investigate</a>
                        <button class="invs-btn-ghost">Dismiss</button>
                    </div>

                @else
                    <div class="invs-incomplete-banner">
                        <div style="font-weight:700;color:var(--ax-warning-fg);margin-bottom:.375rem">Investigation Incomplete</div>
                        <p style="font-size:.875rem;color:var(--ax-warning-fg);margin:.25rem 0">
                            {{ $selected->ai_summary
                                ? \Illuminate\Support\Str::limit($selected->ai_summary, 120)
                                : 'Sales decline confirmed, but available data does not establish a reliable primary driver.' }}
                        </p>
                        <div style="font-size:.8125rem;font-weight:700;color:var(--ax-warning-fg);margin-top:.625rem">Next Best Step</div>
                        <p style="font-size:.8125rem;color:var(--ax-warning-fg);margin:.25rem 0">
                            {{ $selected->ai_recommended_action ?? 'Review availability, pricing, and recent operational changes.' }}
                        </p>
                        <a href="{{ $expandUrl }}" class="invs-btn-warning">Open Investigation</a>
                    </div>
                @endif
            </div>

            {{-- KEY EVIDENCE --}}
            @if($topEvidence->count() > 0)
            <div class="invs-evidence-section">
                <div class="invs-section-header">
                    <span class="invs-section-label" style="margin:0">Key Evidence ({{ $topEvidence->count() }})</span>
                    <a href="{{ $expandUrl }}" class="invs-link">View all evidence &#x2192;</a>
                </div>
                <div class="invs-evidence-grid">
                    @foreach($topEvidence as $ev)
                    @php
                        $evTag = match($ev->strength) { 'strong'=>'invs-ev-tag-strong', 'moderate'=>'invs-ev-tag-moderate', default=>'invs-ev-tag-weak' };
                    @endphp
                    <div class="invs-ev-card">
                        <div class="invs-ev-card-header">
                            <span class="invs-ev-label">{{ $ev->label }}</span>
                            <span class="invs-ev-strength {{ $evTag }}">{{ ucfirst($ev->strength) }}</span>
                        </div>
                        <div class="invs-ev-value">{{ $ev->getFormattedValue() }}</div>
                        @if($ev->source)<div class="invs-ev-source">{{ $ev->source }}</div>@endif
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- ASSIGNMENT --}}
            <div class="invs-assignment-section">
                <div class="invs-section-label" style="margin-bottom:.625rem">Assignment</div>
                @if($selected->assignedTeam || $selected->assignedUser)
                    <div class="invs-assigned-state">
                        <div class="invs-assigned-info">
                            <span class="invs-assigned-dot" style="background:var(--ax-success)"></span>
                            <div>
                                <div class="invs-assigned-name">
                                    {{ $selected->assignedUser?->name ?? $selected->assignedTeam?->name }}
                                </div>
                                @if($selected->assignedTeam && $selected->assignedUser)
                                <div style="font-size:.75rem;color:var(--ax-muted)">{{ $selected->assignedTeam->name }}</div>
                                @endif
                            </div>
                        </div>
                        <span class="invs-status-pill invs-status-progress">Assigned</span>
                    </div>
                @else
                    <div class="invs-unassigned-state">
                        <span class="invs-status-pill invs-status-open">UNASSIGNED</span>
                        @if($slaLabel)
                        <div class="invs-sla-label {{ $slaOverdue ? 'invs-sla-overdue' : 'invs-sla-ok' }}">
                            {{ $slaOverdue ? 'Assignment overdue' : "SLA: {$slaLabel}" }}
                        </div>
                        @endif
                    </div>
                    <div class="invs-assign-btns" style="margin-top:.5rem">
                        <button class="invs-btn-primary" style="font-size:.8125rem;padding:.4rem .875rem">Assign to me</button>
                        <button class="invs-btn-ghost" style="font-size:.8125rem;padding:.4rem .875rem">Assign</button>
                    </div>
                @endif
            </div>

            {{-- Meta --}}
            <div class="invs-panel-meta">
                First detected: {{ $selected->opened_at?->format('M d, Y g:i A') }}
                @if($selected->assignedTeam) &middot; Team: {{ $selected->assignedTeam->name }} @endif
            </div>

            {{-- Expand --}}
            <a href="{{ $expandUrl }}" class="invs-expand-full">
                Expand Investigation &#x2192;
            </a>

        </div>{{-- end .invs-panel-body --}}
    </div>{{-- end .invs-panel --}}
    @endif

</div>{{-- end .invs-wrap --}}

</x-filament-panels::page>
