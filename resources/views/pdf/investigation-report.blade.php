<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Investigation #{{ $investigation->id }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 11px; color: #1f2937; background: #fff; }
        .header { background: linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%); color: #fff; padding: 24px 32px; margin-bottom: 24px; }
        .header-brand { font-size: 18px; font-weight: 700; letter-spacing: -0.5px; margin-bottom: 4px; }
        .header-sub { font-size: 11px; opacity: 0.85; }
        .header-meta { margin-top: 16px; font-size: 10px; opacity: 0.75; }
        .content { padding: 0 32px 32px; }
        .summary-card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin-bottom: 20px; background: #f9fafb; }
        .title { font-size: 15px; font-weight: 700; color: #111827; margin-bottom: 8px; line-height: 1.35; }
        .badges { margin-bottom: 10px; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 9999px; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-right: 4px; margin-bottom: 2px; }
        .badge-high { background: #fee2e2; color: #991b1b; }
        .badge-medium { background: #fef3c7; color: #92400e; }
        .badge-low { background: #dbeafe; color: #1e40af; }
        .badge-gray { background: #f3f4f6; color: #374151; }
        .badge-purple { background: #ede9fe; color: #5b21b6; }
        .badge-green { background: #d1fae5; color: #065f46; }
        .badge-red { background: #fee2e2; color: #991b1b; }
        .badge-yellow { background: #fef3c7; color: #92400e; }
        .badge-blue { background: #dbeafe; color: #1e40af; }
        .description { font-size: 11px; color: #374151; line-height: 1.6; margin-top: 8px; }
        .kv { width: 100%; border-collapse: collapse; margin-top: 12px; }
        .kv td { padding: 4px 10px 4px 0; font-size: 10px; color: #374151; vertical-align: top; }
        .kv td.k { color: #6b7280; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; font-size: 9px; width: 30%; }
        .section-title { font-size: 13px; font-weight: 700; color: #111827; border-bottom: 2px solid #7c3aed; padding-bottom: 6px; margin: 22px 0 14px; }
        .steps { margin-bottom: 8px; }
        .step { display: table; width: 100%; margin-bottom: 12px; }
        .step-num { display: table-cell; width: 28px; vertical-align: top; padding-top: 2px; }
        .step-circle { width: 22px; height: 22px; border-radius: 50%; text-align: center; line-height: 22px; font-size: 10px; font-weight: 700; color: #fff; }
        .step-body { display: table-cell; vertical-align: top; padding-left: 8px; }
        .step-label { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #6b7280; margin-bottom: 3px; }
        .step-text { font-size: 11px; color: #374151; line-height: 1.55; }
        .sc-blue { background: #2563eb; } .sc-violet { background: #7c3aed; } .sc-orange { background: #d97706; }
        .sc-red { background: #dc2626; } .sc-teal { background: #0d9488; } .sc-indigo { background: #a855f7; } .sc-green { background: #16a34a; }
        table.data { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.data th { background: #f3f4f6; text-align: left; padding: 6px 10px; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; color: #6b7280; border: 1px solid #e5e7eb; }
        table.data td { padding: 6px 10px; font-size: 10px; color: #374151; border: 1px solid #e5e7eb; word-break: break-word; }
        table.data tr:nth-child(even) td { background: #f9fafb; }
        .resolved-box { border: 1px solid #bbf7d0; border-radius: 6px; background: #f0fdf4; padding: 10px 12px; margin-top: 10px; }
        .resolved-label { font-size: 9px; font-weight: 700; color: #065f46; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 3px; }
        .resolved-text { font-size: 11px; color: #064e3b; }
        .muted { color: #6b7280; font-style: italic; }
        .footer { margin-top: 24px; padding-top: 12px; border-top: 1px solid #e5e7eb; font-size: 9px; color: #9ca3af; text-align: center; }
    </style>
</head>
<body>

@php
    $inv = $investigation;
    $render = function ($v): string {
        if (is_array($v)) {
            $flat = [];
            array_walk_recursive($v, function ($x) use (&$flat) { if ($x !== null && $x !== '') { $flat[] = is_scalar($x) ? (string) $x : json_encode($x); } });
            return implode('; ', $flat);
        }
        return (string) ($v ?? '');
    };
    $priClass = match($inv->priority) { 'critical' => 'badge-red', 'high' => 'badge-high', 'medium' => 'badge-medium', default => 'badge-low' };
    $statusClass = match($inv->status) { 'open' => 'badge-yellow', 'in_progress' => 'badge-blue', 'resolved' => 'badge-green', 'closed' => 'badge-gray', default => 'badge-gray' };
    $confClass = match($inv->ai_confidence) { 'established' => 'badge-green', 'probable' => 'badge-blue', 'suspected' => 'badge-yellow', default => 'badge-gray' };
    $cp = $currencyPrefix;
@endphp

<div class="header">
    <div class="header-brand">Autnyx</div>
    <div class="header-sub">Investigation Report</div>
    <div class="header-meta">Investigation #{{ $inv->id }} &nbsp;·&nbsp; Generated {{ $generatedAt }}</div>
</div>

<div class="content">

    {{-- Summary --}}
    <div class="summary-card">
        <div class="title">{{ $inv->title ?: ('Investigation #' . $inv->id) }}</div>
        <div class="badges">
            <span class="badge {{ $statusClass }}">{{ ucwords(str_replace('_', ' ', (string) $inv->status)) }}</span>
            <span class="badge {{ $priClass }}">{{ ucfirst((string) $inv->priority) }} priority</span>
            @if($inv->ai_confidence)<span class="badge {{ $confClass }}">{{ ucfirst($inv->ai_confidence) }} confidence</span>@endif
            @if($inv->primary_sku)<span class="badge badge-purple">SKU: {{ $inv->primary_sku }}</span>@endif
            @if($inv->primaryStore)<span class="badge badge-gray">{{ $inv->primaryStore->name }}</span>@endif
            @if($inv->assignedTeam)<span class="badge badge-gray">{{ $inv->assignedTeam->name }}</span>@endif
            @if(($inv->anomaly_count ?? 0) > 1)<span class="badge badge-gray">{{ $inv->anomaly_count }} correlated signals</span>@endif
        </div>
        @if($inv->description)<div class="description">{{ $inv->description }}</div>@endif
        <table class="kv">
            @if($inv->revenue_at_risk)<tr><td class="k">Revenue at risk</td><td>{{ $cp }}{{ number_format((float) $inv->revenue_at_risk, 2) }} <span class="muted">(calculated from detected signals)</span></td></tr>@endif
            @if($inv->ai_revenue_estimate)<tr><td class="k">AI estimate</td><td>{{ $cp }}{{ number_format((float) $inv->ai_revenue_estimate, 2) }} <span class="muted">(AI-generated, not used in calculations)</span></td></tr>@endif
            @if($inv->observed_recovery)<tr><td class="k">Observed recovery</td><td>{{ $cp }}{{ number_format((float) $inv->observed_recovery, 2) }}</td></tr>@endif
            <tr><td class="k">Opened</td><td>{{ optional($inv->opened_at)->format('d M Y, H:i') ?? '—' }}</td></tr>
            @if($inv->resolved_at)<tr><td class="k">Resolved</td><td>{{ $inv->resolved_at->format('d M Y, H:i') }}</td></tr>@endif
        </table>
    </div>

    {{-- AI narrative --}}
    <div class="section-title">Investigation Narrative <span class="muted">(AI-generated from the detected evidence)</span></div>
    @if($inv->ai_summary || $inv->ai_root_cause || $inv->ai_headline)
        @if($inv->ai_generated_at)<div class="muted" style="font-size:9px; margin-bottom:12px;">Narrative generated {{ $inv->ai_generated_at->format('d M Y H:i') }}</div>@endif
        <div class="steps">
            @if($inv->ai_headline)
            <div class="step"><div class="step-num"><div class="step-circle sc-indigo">!</div></div>
                <div class="step-body"><div class="step-label">Headline</div><div class="step-text">{{ $render($inv->ai_headline) }}</div></div></div>
            @endif
            @if($inv->ai_summary)
            <div class="step"><div class="step-num"><div class="step-circle sc-blue">1</div></div>
                <div class="step-body"><div class="step-label">Summary</div><div class="step-text">{{ $render($inv->ai_summary) }}</div></div></div>
            @endif
            <div class="step"><div class="step-num"><div class="step-circle sc-violet">2</div></div>
                <div class="step-body"><div class="step-label">Root cause</div><div class="step-text">{{ $render($inv->ai_root_cause) ?: '—' }}</div></div></div>
            @if($inv->ai_contributing_factors)
            <div class="step"><div class="step-num"><div class="step-circle sc-teal">3</div></div>
                <div class="step-body"><div class="step-label">Contributing factors</div><div class="step-text">{{ $render($inv->ai_contributing_factors) }}</div></div></div>
            @endif
            <div class="step"><div class="step-num"><div class="step-circle sc-red">4</div></div>
                <div class="step-body"><div class="step-label">Recommended action</div><div class="step-text">{{ $render($inv->ai_recommended_action) ?: '—' }}</div></div></div>
            @if($inv->ai_business_impact)
            <div class="step"><div class="step-num"><div class="step-circle sc-orange">5</div></div>
                <div class="step-body"><div class="step-label">Business impact</div><div class="step-text">{{ $render($inv->ai_business_impact) }}</div></div></div>
            @endif
            @if($inv->ai_long_term_fix)
            <div class="step"><div class="step-num"><div class="step-circle sc-green">6</div></div>
                <div class="step-body"><div class="step-label">Long-term fix</div><div class="step-text">{{ $render($inv->ai_long_term_fix) }}</div></div></div>
            @endif
        </div>
    @else
        <p class="muted">The AI narrative has not been generated yet. It is produced automatically during the nightly pipeline, or on demand from the investigation page.</p>
    @endif

    {{-- Signals --}}
    @if($inv->anomalies && $inv->anomalies->count() > 0)
        <div class="section-title">Signals ({{ $inv->anomalies->count() }})</div>
        <table class="data">
            <thead><tr><th>Signal</th><th>Severity</th><th>SKU</th><th>Store</th><th>Detected</th></tr></thead>
            <tbody>
                @foreach($inv->anomalies as $a)
                    <tr>
                        <td>{{ \App\Models\AnomalySetting::RULES[$a->rule_type]['label'] ?? ucwords(str_replace('_', ' ', (string) $a->rule_type)) }}</td>
                        <td>{{ ucfirst((string) $a->severity) }}</td>
                        <td>{{ $a->sku ?? '—' }}</td>
                        <td>{{ $a->store_id ?? '—' }}</td>
                        <td>{{ optional($a->detected_at)->format('d M Y') ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- Actions --}}
    @if($inv->actions && $inv->actions->count() > 0)
        <div class="section-title">Actions ({{ $inv->actions->count() }})</div>
        <table class="data">
            <thead><tr><th>Action</th><th>Type</th><th>Status</th><th>Priority</th><th>Due</th></tr></thead>
            <tbody>
                @foreach($inv->actions as $act)
                    <tr>
                        <td>{{ $act->title }}</td>
                        <td>{{ \App\Models\Action::TYPE_LABELS[$act->action_type] ?? ucwords(str_replace('_', ' ', (string) $act->action_type)) }}</td>
                        <td>{{ ucwords(str_replace('_', ' ', (string) $act->status)) }}</td>
                        <td>{{ ucfirst((string) $act->priority) }}</td>
                        <td>{{ optional($act->due_at)->format('d M Y') ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- Evidence (top rows) --}}
    @if($inv->evidence && $inv->evidence->count() > 0)
        @php $evShown = $inv->evidence->sortByDesc(fn ($e) => $e->direction === 'neutral' ? 0 : 1)->take(15); @endphp
        <div class="section-title">Evidence ({{ $inv->evidence->count() }} rows)</div>
        <table class="data">
            <thead><tr><th>Finding</th><th>Value</th><th>Direction</th></tr></thead>
            <tbody>
                @foreach($evShown as $e)
                    <tr>
                        <td>{{ $e->label }}</td>
                        <td>{{ $e->getFormattedValue() }}</td>
                        <td>{{ ucfirst((string) $e->direction) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if($inv->evidence->count() > 15)<div class="muted" style="font-size:9px; margin-top:6px;">Showing 15 of {{ $inv->evidence->count() }} evidence rows — full evidence is on the investigation page.</div>@endif
    @endif

    {{-- Outcome --}}
    @if($inv->outcome)
        @php $o = $inv->outcome; @endphp
        <div class="section-title">Financial Outcome</div>
        <table class="kv">
            <tr><td class="k">Outcome</td><td>{{ ucwords(str_replace('_', ' ', (string) $o->outcome_type)) }}</td></tr>
            @if($o->observed_recovery !== null)<tr><td class="k">Observed recovery</td><td>{{ $cp }}{{ number_format((float) $o->observed_recovery, 2) }}</td></tr>@endif
            @if($o->cost_to_resolve !== null)<tr><td class="k">Cost to resolve</td><td>{{ $cp }}{{ number_format((float) $o->cost_to_resolve, 2) }}</td></tr>@endif
            @if($o->confirmed_root_cause)<tr><td class="k">Confirmed root cause</td><td>{{ $o->confirmed_root_cause }}</td></tr>@endif
        </table>
    @endif

    {{-- Resolution --}}
    @if(in_array($inv->status, ['resolved', 'closed'], true) && $inv->resolution_notes)
        <div class="resolved-box">
            <div class="resolved-label">{{ $inv->status === 'resolved' ? '✓ Resolved' : '✓ Closed' }}@if($inv->resolved_at) — {{ $inv->resolved_at->format('d M Y') }}@endif</div>
            <div class="resolved-text">{{ $inv->resolution_notes }}</div>
        </div>
    @endif

    <div class="footer">Autnyx · Root Cause Retail Intelligence · Investigation #{{ $inv->id }} · {{ $generatedAt }}</div>

</div>
</body>
</html>
