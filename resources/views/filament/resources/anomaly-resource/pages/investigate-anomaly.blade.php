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
        background:var(--ax-bg); border:1px solid var(--ax-line); border-radius:.875rem;
        box-shadow:var(--ax-shadow); padding:1.25rem 1.5rem; margin-bottom:1.25rem;
    }
    .ia-card-violet { background:var(--ax-accent-soft); border-color:var(--ax-accent-soft-border); }
    .ia-head { display:flex; flex-wrap:wrap; align-items:center; gap:.5rem; }
    .ia-pill {
        display:inline-flex; align-items:center; gap:.35rem;
        padding:.2rem .65rem; border-radius:9999px;
        font-size:.75rem; font-weight:600; white-space:nowrap; line-height:1.4;
    }
    .ia-dot { width:.4rem; height:.4rem; border-radius:9999px; flex-shrink:0; }
    .ia-detected { margin-left:auto; font-size:.8rem; color:var(--ax-faint); }
    .ia-desc { margin-top:.85rem; font-size:.875rem; color:var(--ax-text); line-height:1.65; }
    .ia-label { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--ax-accent); margin:0 0 .25rem; }
    .ia-title { font-size:.95rem; font-weight:700; color:var(--ax-ink); margin:0; }
    .ia-badges { display:flex; flex-wrap:wrap; gap:.5rem; margin-top:.75rem; }
    .ia-ai { margin-top:.85rem; font-size:.875rem; color:var(--ax-text); line-height:1.6; }
    .ia-ai-muted { margin-top:.85rem; font-size:.8rem; color:var(--ax-muted); font-style:italic; }
    .ia-meta { margin-top:.4rem; font-size:.72rem; color:var(--ax-faint); }
    .ia-divider { margin-top:1rem; padding-top:1rem; border-top:1px solid var(--ax-accent-soft-border); font-size:.75rem; color:var(--ax-accent-strong); }
    .ia-empty { background:var(--ax-bg); border:2px dashed var(--ax-line); border-radius:.875rem; padding:2.5rem 1.5rem; text-align:center; margin-bottom:1.25rem; }
    .ia-empty-icon { width:3.25rem; height:3.25rem; margin:0 auto 1rem; display:flex; align-items:center; justify-content:center; border-radius:9999px; background:var(--ax-panel); color:var(--ax-faint); }
    .ia-empty-title { font-size:.9rem; font-weight:700; color:var(--ax-ink); margin:0; }
    .ia-empty-body { margin:.4rem auto 0; max-width:34rem; font-size:.85rem; color:var(--ax-muted); line-height:1.55; }
    .ia-details summary { cursor:pointer; font-size:.75rem; color:var(--ax-faint); user-select:none; }
    .ia-details summary:hover { color:var(--ax-muted); }
    .ia-pre { margin-top:.5rem; border-radius:.5rem; background:var(--ax-panel); border:1px solid var(--ax-line-2); padding:1rem; font-size:.72rem; color:var(--ax-text); overflow-x:auto; }
</style>

@php
    // color name → [background, text]
    // Token-driven so pills flip correctly in dark mode. Orange folds into
    // warning and indigo into the violet accent (accent unified to violet).
    $iaPalette = [
        'red'    => ['var(--ax-danger-soft)',  'var(--ax-danger-fg)'],
        'orange' => ['var(--ax-warning-soft)', 'var(--ax-warning-fg)'],
        'yellow' => ['var(--ax-warning-soft)', 'var(--ax-warning-fg)'],
        'green'  => ['var(--ax-success-soft)', 'var(--ax-success-fg)'],
        'blue'   => ['var(--ax-info-soft)',    'var(--ax-info-fg)'],
        'indigo' => ['var(--ax-accent-soft)',  'var(--ax-accent-strong)'],
        'violet' => ['var(--ax-accent-soft)',  'var(--ax-accent-strong)'],
        'purple' => ['var(--ax-accent-soft)',  'var(--ax-accent-strong)'],
        'gray'   => ['var(--ax-neutral-soft)', 'var(--ax-neutral-fg)'],
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
        <p class="ia-empty-body" style="margin-top:.75rem; font-size:.75rem; color:var(--ax-faint);">
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
