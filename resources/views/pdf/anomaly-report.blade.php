<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Anomaly Report #{{ $anomaly->id }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 11px;
            color: #1f2937;
            background: #ffffff;
        }

        /* ── Header ── */
        .header {
            background: linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%);
            color: white;
            padding: 24px 32px;
            margin-bottom: 24px;
        }
        .header-brand {
            font-size: 18px;
            font-weight: 700;
            letter-spacing: -0.5px;
            margin-bottom: 4px;
        }
        .header-sub {
            font-size: 11px;
            opacity: 0.8;
        }
        .header-meta {
            margin-top: 16px;
            font-size: 10px;
            opacity: 0.7;
        }

        /* ── Content ── */
        .content { padding: 0 32px 32px; }

        /* ── Anomaly summary card ── */
        .summary-card {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 20px;
            background: #f9fafb;
        }
        .badges { margin-bottom: 10px; }
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 9999px;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-right: 4px;
        }
        .badge-high    { background: #fee2e2; color: #991b1b; }
        .badge-medium  { background: #fef3c7; color: #92400e; }
        .badge-low     { background: #dbeafe; color: #1e40af; }
        .badge-gray    { background: #f3f4f6; color: #374151; }
        .badge-purple  { background: #ede9fe; color: #5b21b6; }
        .badge-green   { background: #d1fae5; color: #065f46; }
        .badge-red     { background: #fee2e2; color: #991b1b; }
        .badge-yellow  { background: #fef3c7; color: #92400e; }
        .badge-blue    { background: #dbeafe; color: #1e40af; }

        .description {
            font-size: 11px;
            color: #374151;
            line-height: 1.6;
            margin-top: 8px;
        }
        .detected-at {
            font-size: 9px;
            color: #6b7280;
            margin-top: 6px;
        }

        /* ── Section heading ── */
        .section-title {
            font-size: 13px;
            font-weight: 700;
            color: #111827;
            border-bottom: 2px solid #7c3aed;
            padding-bottom: 6px;
            margin-bottom: 14px;
        }

        /* ── Investigation steps ── */
        .steps { margin-bottom: 24px; }
        .step {
            display: table;
            width: 100%;
            margin-bottom: 12px;
        }
        .step-num {
            display: table-cell;
            width: 28px;
            vertical-align: top;
            padding-top: 2px;
        }
        .step-circle {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            text-align: center;
            line-height: 22px;
            font-size: 10px;
            font-weight: 700;
            color: white;
        }
        .step-body { display: table-cell; vertical-align: top; padding-left: 8px; }
        .step-label {
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6b7280;
            margin-bottom: 3px;
        }
        .step-text {
            font-size: 11px;
            color: #374151;
            line-height: 1.55;
        }
        .step-badge { margin-bottom: 4px; }

        /* Step colours — aligned to the app's design-system chart palette (AX_SERIES) */
        .sc-blue   { background: #2563eb; }
        .sc-violet { background: #7c3aed; }
        .sc-orange { background: #d97706; }
        .sc-red    { background: #dc2626; }
        .sc-teal   { background: #0d9488; }
        .sc-indigo { background: #a855f7; }
        .sc-green  { background: #16a34a; }

        /* ── Action / resolution boxes ── */
        .action-box {
            border: 1px solid #bbf7d0;
            border-radius: 6px;
            background: #f0fdf4;
            padding: 10px 12px;
            margin-top: 8px;
        }
        .action-box-label {
            font-size: 9px;
            font-weight: 700;
            color: #065f46;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 3px;
        }
        .action-box-text { font-size: 11px; color: #064e3b; }
        .action-box-date { font-size: 9px; color: #10b981; margin-top: 3px; }

        .resolved-box {
            border: 1px solid #bbf7d0;
            border-radius: 6px;
            background: #f0fdf4;
            padding: 10px 12px;
            margin-top: 8px;
        }
        .unresolved-box {
            border: 1px solid #fecaca;
            border-radius: 6px;
            background: #fef2f2;
            padding: 10px 12px;
            margin-top: 8px;
        }
        .resolved-label   { font-size: 9px; font-weight: 700; color: #065f46; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 3px; }
        .unresolved-label { font-size: 9px; font-weight: 700; color: #991b1b; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 3px; }
        .resolved-text    { font-size: 11px; color: #064e3b; }
        .unresolved-text  { font-size: 11px; color: #7f1d1d; }

        /* ── Context table ── */
        .context-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
        }
        .context-table th {
            background: #f3f4f6;
            text-align: left;
            padding: 6px 10px;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: #6b7280;
            border: 1px solid #e5e7eb;
        }
        .context-table td {
            padding: 6px 10px;
            font-size: 10px;
            color: #374151;
            border: 1px solid #e5e7eb;
            word-break: break-word;
        }
        .context-table tr:nth-child(even) td { background: #f9fafb; }

        /* ── Footer ── */
        .footer {
            margin-top: 24px;
            padding-top: 12px;
            border-top: 1px solid #e5e7eb;
            font-size: 9px;
            color: #9ca3af;
            text-align: center;
        }
    </style>
</head>
<body>

{{-- ── Header ──────────────────────────────────────────────────────────── --}}
<div class="header">
    <div class="header-brand">Autnyx</div>
    <div class="header-sub">Anomaly Investigation Report</div>
    <div class="header-meta">Report #{{ $anomaly->id }} &nbsp;·&nbsp; Generated {{ $generatedAt }}</div>
</div>

<div class="content">

    {{-- ── Summary card ─────────────────────────────────────────────────── --}}
    <div class="summary-card">
        <div class="badges">
            @php
                $sevClass = match($anomaly->severity) {
                    'high'   => 'badge-high',
                    'medium' => 'badge-medium',
                    'low'    => 'badge-low',
                    default  => 'badge-gray',
                };
                $statusLabel = match($anomaly->investigation_status) {
                    'investigating'     => 'Investigating',
                    'cause_established' => 'Cause Established',
                    'action_taken'      => 'Action Taken',
                    'resolved'          => 'Resolved',
                    'unresolved'        => 'Unresolved',
                    default             => 'Not Started',
                };
                $statusClass = match($anomaly->investigation_status) {
                    'cause_established' => 'badge-blue',
                    'action_taken'      => 'badge-yellow',
                    'resolved'          => 'badge-green',
                    'unresolved'        => 'badge-red',
                    'investigating'     => 'badge-purple',
                    default             => 'badge-gray',
                };
            @endphp
            <span class="badge {{ $sevClass }}">{{ ucfirst($anomaly->severity) }}</span>
            <span class="badge badge-gray">{{ $ruleLabel }}</span>
            @if($anomaly->sku)
                <span class="badge badge-purple">SKU: {{ $anomaly->sku }}</span>
            @endif
            <span class="badge {{ $statusClass }}">{{ $statusLabel }}</span>
        </div>

        <div class="description">{{ $anomaly->description }}</div>
        <div class="detected-at">Detected: {{ $anomaly->detected_at?->format('d M Y, H:i') ?? '—' }}</div>
    </div>

    {{-- ── Investigation Narrative ─────────────────────────────────────── --}}
    <div class="section-title">Investigation Narrative</div>

    @if($investigation)
        @php
            $invStatus = ucwords(str_replace('_', ' ', $investigation->status));
            $invStatusClass = match($investigation->status) {
                'open'        => 'badge-yellow',
                'in_progress' => 'badge-blue',
                'resolved'    => 'badge-green',
                'closed'      => 'badge-gray',
                default       => 'badge-gray',
            };
            $confClass = match($investigation->ai_confidence) {
                'established' => 'badge-green',
                'probable'    => 'badge-blue',
                'suspected'   => 'badge-yellow',
                default       => 'badge-gray',
            };
        @endphp

        {{-- Investigation metadata --}}
        <div style="margin-bottom:14px;">
            <span class="badge {{ $invStatusClass }}">{{ $invStatus }}</span>
            <span class="badge badge-gray">{{ ucfirst($investigation->priority) }} Priority</span>
            @if($investigation->ai_confidence)
                <span class="badge {{ $confClass }}">{{ ucfirst($investigation->ai_confidence) }} Confidence</span>
            @endif
            @if($investigation->assignedTeam)
                <span class="badge badge-gray">{{ $investigation->assignedTeam->name }}</span>
            @endif
            @if($investigation->anomaly_count > 1)
                <span class="badge badge-gray">{{ $investigation->anomaly_count }} correlated anomalies</span>
            @endif
        </div>
        <div style="font-size:9px; color:#6b7280; margin-bottom:14px; font-style:italic;">
            Investigation #{{ $investigation->id }} · {{ $investigation->title }}
            @if($investigation->ai_generated_at)
                · Narrative generated {{ $investigation->ai_generated_at->format('d M Y H:i') }}
            @endif
        </div>

        @if($investigation->ai_summary)
            <div class="steps">

                {{-- Summary --}}
                <div class="step">
                    <div class="step-num"><div class="step-circle sc-blue">1</div></div>
                    <div class="step-body">
                        <div class="step-label">Summary</div>
                        <div class="step-text">{{ $investigation->ai_summary }}</div>
                    </div>
                </div>

                {{-- Root cause --}}
                <div class="step">
                    <div class="step-num"><div class="step-circle sc-violet">2</div></div>
                    <div class="step-body">
                        <div class="step-label">
                            Root Cause &nbsp;
                            @if($investigation->ai_confidence)
                                <span class="badge {{ $confClass }}">{{ ucfirst($investigation->ai_confidence) }}</span>
                            @endif
                        </div>
                        <div class="step-text">{{ $investigation->ai_root_cause ?? '—' }}</div>
                    </div>
                </div>

                {{-- Recommended action --}}
                <div class="step">
                    <div class="step-num"><div class="step-circle sc-red">3</div></div>
                    <div class="step-body">
                        <div class="step-label">Recommended Action</div>
                        <div class="step-text">{{ $investigation->ai_recommended_action ?? '—' }}</div>
                    </div>
                </div>

                @if($investigation->revenue_at_risk)
                {{-- Revenue at risk --}}
                <div class="step">
                    <div class="step-num"><div class="step-circle sc-orange">4</div></div>
                    <div class="step-body">
                        <div class="step-label">Revenue at Risk (AI Estimate)</div>
                        <div class="step-text">{{ $currencyPrefix }}{{ number_format($investigation->revenue_at_risk, 2) }}</div>
                    </div>
                </div>
                @endif

                {{-- Outcome --}}
                @if($investigation->outcome)
                    @php $outcome = $investigation->outcome; @endphp
                    <div class="step">
                        <div class="step-num"><div class="step-circle sc-green">{{ $investigation->revenue_at_risk ? 5 : 4 }}</div></div>
                        <div class="step-body">
                            <div class="step-label">Financial Outcome</div>
                            <div class="step-text">
                                Type: {{ $outcome->outcome_type }}
                                @if($outcome->observed_recovery)
                                    · Recovery: {{ $currencyPrefix }}{{ number_format($outcome->observed_recovery, 2) }}
                                @endif
                                @if($outcome->confirmed_root_cause)
                                    · {{ $outcome->confirmed_root_cause }}
                                @endif
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Resolution --}}
                @if($investigation->status === 'resolved' || $investigation->status === 'closed')
                    <div @if($investigation->status === 'resolved') class="resolved-box" @else class="action-box" @endif>
                        <div @if($investigation->status === 'resolved') class="resolved-label" @else class="action-box-label" @endif>
                            {{ $investigation->status === 'resolved' ? '✓ Resolved' : '✓ Closed' }}
                            @if($investigation->resolved_at) — {{ $investigation->resolved_at->format('d M Y') }} @endif
                        </div>
                        @if($investigation->resolution_notes)
                            <div @if($investigation->status === 'resolved') class="resolved-text" @else class="action-box-text" @endif>
                                {{ $investigation->resolution_notes }}
                            </div>
                        @endif
                    </div>
                @endif

            </div>
        @else
            <p style="color:#6b7280; font-style:italic; margin-bottom:20px;">
                Investigation is open but the AI narrative has not yet been generated. It will be produced automatically during the nightly pipeline at 03:30 AM.
            </p>
        @endif

    @else
        <p style="color:#6b7280; font-style:italic; margin-bottom:20px;">
            This anomaly has not yet been correlated into an investigation. Investigation correlation runs nightly at 02:00 AM. Once an investigation is created, the AI narrative will be generated and available in this report.
        </p>
    @endif

    {{-- ── Raw context ───────────────────────────────────────────────────── --}}
    @if($anomaly->context && count($anomaly->context) > 0)
        <div class="section-title">Detection Data</div>
        <table class="context-table">
            <thead>
                <tr>
                    <th style="width:40%">Field</th>
                    <th>Value</th>
                </tr>
            </thead>
            <tbody>
                @foreach($anomaly->context as $key => $value)
                    <tr>
                        <td>{{ str_replace('_', ' ', ucwords($key, '_')) }}</td>
                        <td>{{ is_array($value) ? implode(', ', $value) : $value }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- ── Footer ───────────────────────────────────────────────────────── --}}
    <div class="footer">
        Autnyx · Root Cause Retail Intelligence · Anomaly #{{ $anomaly->id }} · {{ $generatedAt }}
    </div>

</div>
</body>
</html>
