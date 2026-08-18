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

        /* Step colours */
        .sc-blue   { background: #3b82f6; }
        .sc-violet { background: #8b5cf6; }
        .sc-orange { background: #f97316; }
        .sc-red    { background: #ef4444; }
        .sc-teal   { background: #14b8a6; }
        .sc-indigo { background: #6366f1; }
        .sc-green  { background: #22c55e; }

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

    {{-- ── AI Investigation ─────────────────────────────────────────────── --}}
    @if($anomaly->isInvestigated())
        <div class="section-title">AI Investigation</div>
        <div class="steps">

            {{-- Step 1 --}}
            <div class="step">
                <div class="step-num"><div class="step-circle sc-blue">1</div></div>
                <div class="step-body">
                    <div class="step-label">What changed?</div>
                    <div class="step-text">{{ $anomaly->ai_what ?? '—' }}</div>
                </div>
            </div>

            {{-- Step 2 --}}
            <div class="step">
                <div class="step-num"><div class="step-circle sc-violet">2</div></div>
                <div class="step-body">
                    <div class="step-label">
                        Why did it change?
                        &nbsp;
                        @php
                            $confClass = match($anomaly->ai_confidence) {
                                'established' => 'badge-green',
                                'probable'    => 'badge-blue',
                                'suspected'   => 'badge-yellow',
                                default       => 'badge-gray',
                            };
                        @endphp
                        <span class="badge {{ $confClass }}">{{ $anomaly->getConfidenceLabel() }}</span>
                    </div>
                    <div class="step-text">{{ $anomaly->ai_why ?? '—' }}</div>
                </div>
            </div>

            {{-- Step 3 --}}
            <div class="step">
                <div class="step-num"><div class="step-circle sc-orange">3</div></div>
                <div class="step-body">
                    <div class="step-label">
                        How big is the problem?
                        &nbsp;
                        @php
                            $trajClass = match($anomaly->ai_trajectory) {
                                'widening'  => 'badge-red',
                                'stable'    => 'badge-yellow',
                                'narrowing' => 'badge-green',
                                default     => 'badge-gray',
                            };
                        @endphp
                        <span class="badge {{ $trajClass }}">{{ ucfirst($anomaly->ai_trajectory ?? 'unknown') }}</span>
                    </div>
                    <div class="step-text">{{ $anomaly->ai_how_big ?? '—' }}</div>
                </div>
            </div>

            {{-- Step 4 --}}
            <div class="step">
                <div class="step-num"><div class="step-circle sc-red">4</div></div>
                <div class="step-body">
                    <div class="step-label">
                        What should we do?
                        &nbsp;
                        @php
                            $gateClass = match($anomaly->ai_recommendation_gate) {
                                'act'         => 'badge-red',
                                'investigate' => 'badge-yellow',
                                default       => 'badge-blue',
                            };
                        @endphp
                        <span class="badge {{ $gateClass }}">{{ $anomaly->getGateLabel() }}</span>
                    </div>
                    <div class="step-text">{{ $anomaly->ai_action ?? '—' }}</div>
                </div>
            </div>

            {{-- Step 5 --}}
            <div class="step">
                <div class="step-num"><div class="step-circle sc-teal">5</div></div>
                <div class="step-body">
                    <div class="step-label">
                        Pattern or one-off?
                        &nbsp;
                        <span class="badge {{ $anomaly->ai_is_recurring ? 'badge-yellow' : 'badge-green' }}">
                            {{ $anomaly->ai_is_recurring ? 'Recurring' : 'One-off' }}
                        </span>
                    </div>
                    <div class="step-text">{{ $anomaly->ai_pattern ?? '—' }}</div>
                </div>
            </div>

            {{-- Step 6 --}}
            <div class="step">
                <div class="step-num"><div class="step-circle sc-indigo">6</div></div>
                <div class="step-body">
                    <div class="step-label">What else is connected?</div>
                    <div class="step-text">
                        {{ $anomaly->ai_related_summary ?? 'No connected anomalies found.' }}
                        @if(!empty($anomaly->ai_related_anomaly_ids))
                            &nbsp;({{ count($anomaly->ai_related_anomaly_ids) }} related open anomaly/anomalies)
                        @endif
                    </div>
                </div>
            </div>

            {{-- Step 7 --}}
            <div class="step">
                <div class="step-num"><div class="step-circle sc-green">7</div></div>
                <div class="step-body">
                    <div class="step-label">Did it work?</div>
                    <div class="step-text">
                        {{ $anomaly->ai_outcome ?? ($anomaly->action_taken_at ? 'Action taken on ' . $anomaly->action_taken_at->toDateString() . '. Outcome not yet recorded.' : 'No action taken yet.') }}
                    </div>

                    @if($anomaly->action_notes)
                        <div class="action-box">
                            <div class="action-box-label">Action taken</div>
                            <div class="action-box-text">{{ $anomaly->action_notes }}</div>
                            @if($anomaly->action_taken_at)
                                <div class="action-box-date">{{ $anomaly->action_taken_at->format('d M Y, H:i') }}</div>
                            @endif
                        </div>
                    @endif

                    @if($anomaly->resolved_at)
                        @if($anomaly->investigation_status === 'resolved')
                            <div class="resolved-box">
                                <div class="resolved-label">✓ Resolved — {{ $anomaly->resolved_at->toDateString() }}</div>
                                @if($anomaly->resolution_notes)
                                    <div class="resolved-text">{{ $anomaly->resolution_notes }}</div>
                                @endif
                            </div>
                        @else
                            <div class="unresolved-box">
                                <div class="unresolved-label">✗ Unresolved — {{ $anomaly->resolved_at->toDateString() }}</div>
                                @if($anomaly->resolution_notes)
                                    <div class="unresolved-text">{{ $anomaly->resolution_notes }}</div>
                                @endif
                            </div>
                        @endif
                    @endif
                </div>
            </div>

        </div>

    @else
        <div class="section-title">AI Investigation</div>
        <p style="color:#6b7280; font-style:italic; margin-bottom:20px;">No investigation has been run for this anomaly yet.</p>
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
