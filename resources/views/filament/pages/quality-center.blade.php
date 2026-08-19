<x-filament-panels::page>
    @php $r = $this->getReport(); @endphp

    <style>
        .qc-wrap { display:flex; flex-direction:column; gap:1.25rem; }
        .qc-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:.9rem; }
        @media(min-width:900px){ .qc-grid{ grid-template-columns:repeat(4,1fr); } }
        .qc-tile { background:#fff; border:1px solid #e5e7eb; border-radius:.75rem; padding:1rem; box-shadow:0 1px 4px rgba(0,0,0,.05); }
        .qc-tile .lbl { font-size:.7rem; text-transform:uppercase; letter-spacing:.05em; color:#6b7280; }
        .qc-tile .val { font-size:1.6rem; font-weight:800; color:#111827; margin-top:.2rem; }
        .qc-card { background:#fff; border:1px solid #e5e7eb; border-radius:.875rem; box-shadow:0 1px 4px rgba(0,0,0,.06); overflow:hidden; }
        .qc-card h3 { font-size:.9rem; font-weight:700; color:#111827; padding:.85rem 1.15rem; border-bottom:1px solid #f3f4f6; margin:0; }
        .qc-card .body { padding:1rem 1.15rem; }
        .qc-funnel-row { display:flex; align-items:center; gap:.75rem; margin-bottom:.5rem; }
        .qc-funnel-row .name { width:170px; font-size:.78rem; color:#374151; }
        .qc-funnel-bar { flex:1; background:#f3f4f6; border-radius:9999px; height:20px; overflow:hidden; }
        .qc-funnel-bar > span { display:block; height:100%; background:linear-gradient(90deg,#7c3aed,#6366f1); }
        .qc-funnel-row .cnt { width:60px; text-align:right; font-weight:700; font-size:.8rem; }
        table.qc-tbl { width:100%; border-collapse:collapse; font-size:.78rem; }
        table.qc-tbl th { text-align:left; color:#6b7280; font-size:.68rem; text-transform:uppercase; padding:.4rem .6rem; border-bottom:1px solid #e5e7eb; }
        table.qc-tbl td { padding:.45rem .6rem; border-bottom:1px solid #f3f4f6; }
        .qc-muted { color:#6b7280; font-size:.8rem; }
        .qc-flag { background:#fef3c7; color:#92400e; padding:.15rem .5rem; border-radius:9999px; font-size:.68rem; font-weight:700; }
    </style>

    @if(empty($r))
        <div class="qc-card"><div class="body qc-muted">No tenant selected.</div></div>
    @else
    <div class="qc-wrap">

        {{-- Headline rates --}}
        @php $rates = $r['rates'] ?? []; @endphp
        <div class="qc-grid">
            <div class="qc-tile"><div class="lbl">Acceptance rate</div><div class="val">{{ $rates['acceptance_rate'] !== null ? $rates['acceptance_rate'].'%' : '—' }}</div></div>
            <div class="qc-tile"><div class="lbl">False-positive rate</div><div class="val">{{ $rates['false_positive_rate'] !== null ? $rates['false_positive_rate'].'%' : '—' }}</div></div>
            <div class="qc-tile"><div class="lbl">Resolution rate</div><div class="val">{{ $rates['resolution_rate'] !== null ? $rates['resolution_rate'].'%' : '—' }}</div></div>
            <div class="qc-tile"><div class="lbl">Action completion</div><div class="val">{{ $rates['action_completion_rate'] !== null ? $rates['action_completion_rate'].'%' : '—' }}</div></div>
        </div>

        {{-- Overall score --}}
        @php $score = $r['overall_score'] ?? null; @endphp
        <div class="qc-card">
            <h3>Overall quality score</h3>
            <div class="body">
                @if($score && ($score['available'] ?? false))
                    <div style="display:flex; align-items:baseline; gap:.75rem;">
                        <span style="font-size:2rem; font-weight:800; color:#111827;">{{ $score['score'] }}</span>
                        <span class="qc-muted">/ 100 · n={{ $score['sample_size'] }} resolved</span>
                    </div>
                    <div class="qc-muted" style="margin-top:.4rem;">Formula: {{ $score['formula'] }}</div>
                @else
                    <div class="qc-muted">{{ $score['reason'] ?? 'Score not available.' }}</div>
                    <div class="qc-muted" style="margin-top:.35rem;">We deliberately avoid an accuracy claim without a statistically meaningful sample or ground truth.</div>
                @endif
            </div>
        </div>

        {{-- Funnel --}}
        <div class="qc-card">
            <h3>Quality funnel</h3>
            <div class="body">
                @php
                    $funnel = $r['funnel'] ?? [];
                    $max = collect($funnel)->max('count') ?: 1;
                @endphp
                @foreach($funnel as $stage)
                    <div class="qc-funnel-row">
                        <span class="name">{{ $stage['stage'] }}</span>
                        <span class="qc-funnel-bar"><span style="width:{{ $max > 0 ? max(2, ($stage['count']/$max)*100) : 0 }}%;"></span></span>
                        <span class="cnt">{{ number_format($stage['count']) }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="qc-grid" style="grid-template-columns:1fr;">
            {{-- Evidence quality + action quality --}}
            @php $ev = $r['evidence'] ?? []; $aq = $r['action_quality'] ?? []; $times = $r['times'] ?? []; @endphp
            <div class="qc-card">
                <h3>Evidence &amp; action quality</h3>
                <div class="body" style="display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
                    <div>
                        <div class="qc-muted" style="margin-bottom:.4rem;">Evidence strength</div>
                        <div class="qc-funnel-row"><span class="name">Strong</span><span class="cnt">{{ $ev['strong'] ?? 0 }}</span></div>
                        <div class="qc-funnel-row"><span class="name">Moderate</span><span class="cnt">{{ $ev['moderate'] ?? 0 }}</span></div>
                        <div class="qc-funnel-row"><span class="name">Insufficient</span><span class="cnt">{{ $ev['insufficient'] ?? 0 }}</span></div>
                    </div>
                    <div>
                        <div class="qc-muted" style="margin-bottom:.4rem;">Actions</div>
                        <div class="qc-funnel-row"><span class="name">Completed</span><span class="cnt">{{ $aq['completed'] ?? 0 }}</span></div>
                        <div class="qc-funnel-row"><span class="name">In progress</span><span class="cnt">{{ $aq['in_progress'] ?? 0 }}</span></div>
                        <div class="qc-funnel-row"><span class="name">Cancelled</span><span class="cnt">{{ $aq['cancelled'] ?? 0 }}</span></div>
                        <div class="qc-funnel-row"><span class="name">With recovery</span><span class="cnt">{{ $aq['completed_with_recovery'] ?? 0 }}</span></div>
                    </div>
                </div>
                <div class="body qc-muted" style="border-top:1px solid #f3f4f6;">
                    Avg time to action: <strong>{{ $times['avg_hours_to_action'] !== null ? $times['avg_hours_to_action'].'h' : '—' }}</strong> ·
                    Avg time to resolution: <strong>{{ $times['avg_hours_to_resolution'] !== null ? $times['avg_hours_to_resolution'].'h' : '—' }}</strong>
                </div>
            </div>

            {{-- Rule performance --}}
            <div class="qc-card">
                <h3>Rule performance</h3>
                <div class="body">
                    @php $rp = $r['rule_performance'] ?? []; $noisyRules = collect($r['noisy']['rules'] ?? [])->pluck('rule_type')->all(); @endphp
                    @if(empty($rp))
                        <div class="qc-muted">No detections yet.</div>
                    @else
                        <table class="qc-tbl">
                            <thead><tr><th>Rule</th><th>Detections</th><th>Dismissals</th><th>FP rate</th><th>Investigations</th></tr></thead>
                            <tbody>
                                @foreach($rp as $rule)
                                    <tr>
                                        <td>{{ $rule['label'] }} @if(in_array($rule['rule_type'], $noisyRules))<span class="qc-flag">noisy</span>@endif</td>
                                        <td>{{ $rule['detections'] }}</td>
                                        <td>{{ $rule['dismissals'] }}</td>
                                        <td>{{ $rule['fp_rate'] !== null ? $rule['fp_rate'].'%' : '—' }}</td>
                                        <td>{{ $rule['investigations'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>

            {{-- Noisy SKUs --}}
            @php $noisySkus = $r['noisy']['skus'] ?? []; @endphp
            @if(!empty($noisySkus))
            <div class="qc-card">
                <h3>Noisiest SKUs</h3>
                <div class="body">
                    <table class="qc-tbl">
                        <thead><tr><th>SKU</th><th>Detections</th><th>False positives</th></tr></thead>
                        <tbody>
                            @foreach($noisySkus as $s)
                                <tr><td>{{ $s['sku'] }}</td><td>{{ $s['detections'] }}</td><td>{{ $s['fp'] }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="qc-muted" style="margin-top:.6rem;">Review these in Anomaly Settings — this page never changes rules automatically.</div>
                </div>
            </div>
            @endif
        </div>
    </div>
    @endif
</x-filament-panels::page>
