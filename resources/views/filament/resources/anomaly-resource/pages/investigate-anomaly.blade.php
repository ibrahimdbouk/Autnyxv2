<x-filament-panels::page>

{{--
    Self-contained inline styling. This project does NOT compile Tailwind
    utility classes for custom Filament blade views (see the dashboard /
    investigation pages, which all use scoped <style> blocks). Using bare
    Tailwind utilities here left the page unstyled and blew a 1.5px status-dot
    SVG up into a full-width black circle. All styling below is explicit.
--}}
<style>
    .ia-card {
        background:#fff; border:1px solid #e5e7eb; border-radius:.875rem;
        box-shadow:0 1px 4px rgba(0,0,0,.06); padding:1.25rem 1.5rem; margin-bottom:1.25rem;
    }
    .ia-card-violet { background:#f5f3ff; border-color:#ddd6fe; }
    .ia-head { display:flex; flex-wrap:wrap; align-items:center; gap:.5rem; }
    .ia-pill {
        display:inline-flex; align-items:center; gap:.35rem;
        padding:.2rem .65rem; border-radius:9999px;
        font-size:.75rem; font-weight:600; white-space:nowrap; line-height:1.4;
    }
    .ia-dot { width:.4rem; height:.4rem; border-radius:9999px; flex-shrink:0; }
    .ia-detected { margin-left:auto; font-size:.8rem; color:#9ca3af; }
    .ia-desc { margin-top:.85rem; font-size:.875rem; color:#374151; line-height:1.65; }
    .ia-label { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#7c3aed; margin:0 0 .25rem; }
    .ia-title { font-size:.95rem; font-weight:700; color:#111827; margin:0; }
    .ia-badges { display:flex; flex-wrap:wrap; gap:.5rem; margin-top:.75rem; }
    .ia-ai { margin-top:.85rem; font-size:.875rem; color:#374151; line-height:1.6; }
    .ia-ai-muted { margin-top:.85rem; font-size:.8rem; color:#6b7280; font-style:italic; }
    .ia-meta { margin-top:.4rem; font-size:.72rem; color:#9ca3af; }
    .ia-divider { margin-top:1rem; padding-top:1rem; border-top:1px solid #ddd6fe; font-size:.75rem; color:#6d28d9; }
    .ia-empty { background:#fff; border:2px dashed #e5e7eb; border-radius:.875rem; padding:2.5rem 1.5rem; text-align:center; margin-bottom:1.25rem; }
    .ia-empty-icon { width:3.25rem; height:3.25rem; margin:0 auto 1rem; display:flex; align-items:center; justify-content:center; border-radius:9999px; background:#f9fafb; color:#9ca3af; }
    .ia-empty-title { font-size:.9rem; font-weight:700; color:#111827; margin:0; }
    .ia-empty-body { margin:.4rem auto 0; max-width:34rem; font-size:.85rem; color:#6b7280; line-height:1.55; }
    .ia-details summary { cursor:pointer; font-size:.75rem; color:#9ca3af; user-select:none; }
    .ia-details summary:hover { color:#6b7280; }
    .ia-pre { margin-top:.5rem; border-radius:.5rem; background:#f9fafb; border:1px solid #f3f4f6; padding:1rem; font-size:.72rem; color:#4b5563; overflow-x:auto; }

    .dark .ia-card { background:#1f2937; border-color:#374151; }
    .dark .ia-card-violet { background:#2e1065; border-color:#5b21b6; }
    .dark .ia-desc, .dark .ia-ai { color:#d1d5db; }
    .dark .ia-title, .dark .ia-empty-title { color:#f9fafb; }
    .dark .ia-empty { border-color:#374151; }
    .dark .ia-empty-icon { background:#111827; }
    .dark .ia-pre { background:#111827; border-color:#374151; color:#9ca3af; }
</style>

@php
    // color name → [background, text]
    $iaPalette = [
        'red'    => ['#fee2e2', '#991b1b'],
        'orange' => ['#ffedd5', '#9a3412'],
        'yellow' => ['#fef3c7', '#92400e'],
        'green'  => ['#dcfce7', '#166534'],
        'blue'   => ['#dbeafe', '#1e40af'],
        'indigo' => ['#e0e7ff', '#3730a3'],
        'violet' => ['#ede9fe', '#6d28d9'],
        'purple' => ['#ede9fe', '#6d28d9'],
        'gray'   => ['#f3f4f6', '#374151'],
    ];
    $iaPill = function (string $color) use ($iaPalette): string {
        $c = $iaPalette[$color] ?? $iaPalette['gray'];
        return 'background:' . $c[0] . ';color:' . $c[1] . ';';
    };

    $record = $this->record;
    $sevColor = match ($record->severity) {
        'high'   => 'red',
        'medium' => 'yellow',
        default  => 'blue',
    };
@endphp

{{-- ── Header summary card ──────────────────────────────────────────── --}}
<div class="ia-card">
    <div class="ia-head">
        <span class="ia-pill" style="{{ $iaPill($sevColor) }} text-transform:uppercase; letter-spacing:.03em;">
            {{ ucfirst($record->severity) }}
        </span>

        <span class="ia-pill" style="{{ $iaPill('gray') }}">{{ $record->getRuleLabel() }}</span>

        @if($record->sku)
            <span class="ia-pill" style="{{ $iaPill('violet') }}">SKU: {{ $record->sku }}</span>
        @endif

        @if($record->store_id)
            <span class="ia-pill" style="{{ $iaPill('indigo') }}">Store {{ $record->store_id }}</span>
        @endif

        <span class="ia-detected">Detected {{ $record->detected_at?->diffForHumans() }}</span>
    </div>

    <p class="ia-desc">{{ $record->description }}</p>

    @if($record->investigation_status && $record->investigation_status !== 'not_started')
        @php
            $statusLabel = match ($record->investigation_status) {
                'investigating'     => 'Investigating',
                'cause_established' => 'Cause Established',
                'action_taken'      => 'Action Taken',
                'resolved'          => 'Resolved',
                'unresolved'        => 'Unresolved',
                default             => 'Detected',
            };
            $statusColor = match ($record->investigation_status) {
                'cause_established' => 'blue',
                'action_taken'      => 'yellow',
                'resolved'          => 'green',
                'unresolved'        => 'red',
                'investigating'     => 'purple',
                default             => 'gray',
            };
            $statusText = $iaPalette[$statusColor][1] ?? $iaPalette['gray'][1];
        @endphp
        <div style="margin-top:1rem;">
            <span class="ia-pill" style="{{ $iaPill($statusColor) }}">
                <span class="ia-dot" style="background:{{ $statusText }};"></span>
                {{ $statusLabel }}
            </span>
        </div>
    @endif
</div>

{{-- ── Investigation link ────────────────────────────────────────────── --}}
@if($record->investigation)
    @php $inv = $record->investigation; @endphp
    <div class="ia-card ia-card-violet">
        <p class="ia-label">Linked Investigation</p>
        <h3 class="ia-title">{{ $inv->title }}</h3>

        <div class="ia-badges">
            @php
                $invStatusColor = match ($inv->status) {
                    'open'        => 'yellow',
                    'in_progress' => 'blue',
                    'resolved'    => 'green',
                    'closed'      => 'gray',
                    default       => 'gray',
                };
            @endphp
            <span class="ia-pill" style="{{ $iaPill($invStatusColor) }}">
                {{ ucwords(str_replace('_', ' ', $inv->status)) }}
            </span>

            @php
                $invPriColor = match ($inv->priority) {
                    'critical' => 'red',
                    'high'     => 'orange',
                    'medium'   => 'blue',
                    default    => 'gray',
                };
            @endphp
            <span class="ia-pill" style="{{ $iaPill($invPriColor) }}">
                {{ ucfirst($inv->priority) }} Priority
            </span>

            @if($inv->ai_confidence)
                @php
                    $confColor = match ($inv->ai_confidence) {
                        'established' => 'green',
                        'probable'    => 'blue',
                        'suspected'   => 'yellow',
                        default       => 'gray',
                    };
                @endphp
                <span class="ia-pill" style="{{ $iaPill($confColor) }}">
                    {{ ucfirst($inv->ai_confidence) }} confidence
                </span>
            @endif

            @if($inv->assignedTeam)
                <span class="ia-pill" style="{{ $iaPill('gray') }}">{{ $inv->assignedTeam->name }}</span>
            @endif
        </div>

        @if($inv->ai_summary)
            <p class="ia-ai">{{ \Illuminate\Support\Str::limit($inv->ai_summary, 320) }}</p>
            <p class="ia-meta">Narrative generated {{ $inv->ai_generated_at?->diffForHumans() }}</p>
        @else
            <p class="ia-ai-muted">
                AI narrative not yet generated — it will be produced during the nightly pipeline at 03:30 AM, or click “Generate Narrative” on the investigation page.
            </p>
        @endif

        @if($inv->anomaly_count > 1)
            <div class="ia-divider">
                This investigation groups {{ $inv->anomaly_count }} correlated anomalies. Click “View Investigation” above to see the full picture, all evidence, and recommended actions.
            </div>
        @endif
    </div>
@else
    <div class="ia-empty">
        <div class="ia-empty-icon">
            <svg style="width:1.75rem;height:1.75rem" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
        </div>
        <h3 class="ia-empty-title">Pending investigation correlation</h3>
        <p class="ia-empty-body">
            This anomaly has not yet been correlated into an investigation. Investigation correlation runs nightly at 02:00 AM alongside anomaly detection.
        </p>
        <p class="ia-empty-body" style="margin-top:.75rem; font-size:.75rem; color:#9ca3af;">
            Once correlated, an Investigation object will be created grouping this anomaly with related signals on the same SKU or store. You can then view the full AI-powered analysis, evidence package, and actions from the Investigation page.
        </p>
    </div>
@endif

{{-- ── Demand vs best-fit forecast chart (forecast-break anomalies) ── --}}
@php
    $forecastSvg = $record->rule_type === 'demand_forecast_break'
        ? app(\App\Services\Anomaly\ForecastChartService::class)->svgForAnomaly($record)
        : null;
@endphp
@if($forecastSvg)
    <div class="ia-card">
        <p class="ia-label">Demand vs best-fit forecast</p>
        <p class="ia-title">Why this flagged</p>
        <p class="ia-desc">Actual daily demand (bars) against this SKU's own Croston/SBA forecast (line) and its tolerance band. The highlighted window is the recent period the rule compared — the deviation there is what triggered the anomaly.</p>
        <div style="margin-top:.75rem; overflow-x:auto;">{!! $forecastSvg !!}</div>
    </div>
@endif

{{-- ── Raw detection context (collapsed) ──────────────────────────── --}}
@if($record->context)
    <details class="ia-details">
        <summary>▶ Raw detection context</summary>
        <pre class="ia-pre">{{ json_encode($record->context, JSON_PRETTY_PRINT) }}</pre>
    </details>
@endif

</x-filament-panels::page>
