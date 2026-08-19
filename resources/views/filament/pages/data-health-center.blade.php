<x-filament-panels::page>
    @php
        $overall   = $this->getOverall();
        $snapshots = $this->getSnapshots();
        $statusColors = [
            'healthy'  => ['#dcfce7', '#166534', '#16a34a'],
            'warning'  => ['#fef3c7', '#92400e', '#d97706'],
            'critical' => ['#fee2e2', '#991b1b', '#dc2626'],
            'no_data'  => ['#f3f4f6', '#374151', '#9ca3af'],
        ];
        $ov = $statusColors[$overall['status']] ?? $statusColors['no_data'];
    @endphp

    <style>
        .dh-wrap { display:flex; flex-direction:column; gap:1.25rem; }
        .dh-summary { display:flex; flex-wrap:wrap; align-items:center; gap:1.5rem; background:#fff; border:1px solid #e5e7eb; border-radius:1rem; padding:1.5rem; box-shadow:0 1px 4px rgba(0,0,0,.06); }
        .dh-score { font-size:2.5rem; font-weight:800; line-height:1; }
        .dh-pill { display:inline-flex; align-items:center; gap:.35rem; padding:.3rem .8rem; border-radius:9999px; font-size:.8rem; font-weight:700; text-transform:capitalize; }
        .dh-muted { color:#6b7280; font-size:.8rem; }
        .dh-grid { display:grid; grid-template-columns:1fr; gap:1rem; }
        @media(min-width:768px){ .dh-grid{ grid-template-columns:1fr 1fr; } }
        @media(min-width:1280px){ .dh-grid{ grid-template-columns:1fr 1fr 1fr; } }
        .dh-card { background:#fff; border:1px solid #e5e7eb; border-radius:.875rem; box-shadow:0 1px 4px rgba(0,0,0,.06); overflow:hidden; }
        .dh-card-head { display:flex; align-items:center; justify-content:space-between; padding:.9rem 1.15rem; border-bottom:1px solid #f3f4f6; }
        .dh-card-title { font-weight:700; font-size:.95rem; color:#111827; }
        .dh-card-body { padding:1rem 1.15rem; display:flex; flex-direction:column; gap:.7rem; }
        .dh-row { display:flex; justify-content:space-between; font-size:.8rem; }
        .dh-row .k { color:#6b7280; }
        .dh-row .v { color:#111827; font-weight:600; }
        .dh-bar { height:6px; border-radius:9999px; background:#f3f4f6; overflow:hidden; }
        .dh-bar > span { display:block; height:100%; }
        .dh-warn { font-size:.75rem; padding:.4rem .6rem; border-radius:.5rem; margin-top:.15rem; }
        .dh-warn.warning { background:#fef3c7; color:#92400e; }
        .dh-warn.critical { background:#fee2e2; color:#991b1b; }
        .dh-affected { font-size:.72rem; color:#6b7280; border-top:1px dashed #e5e7eb; padding-top:.6rem; }
    </style>

    <div class="dh-wrap">
        {{-- Overall summary --}}
        <div class="dh-summary">
            <div style="display:flex; flex-direction:column; gap:.35rem;">
                <div class="dh-score" style="color:{{ $ov[1] }};">
                    {{ $overall['score'] !== null ? number_format($overall['score'], 0) : '—' }}<span style="font-size:1rem; color:#9ca3af;">/100</span>
                </div>
                <span class="dh-pill" style="background:{{ $ov[0] }}; color:{{ $ov[1] }};">
                    <span style="width:.5rem;height:.5rem;border-radius:9999px;background:{{ $ov[2] }};"></span>
                    {{ str_replace('_',' ', $overall['status']) }}
                </span>
            </div>
            <div style="flex:1; min-width:200px; display:flex; flex-direction:column; gap:.3rem;">
                <div style="font-weight:700; color:#111827;">Overall data trust</div>
                <div class="dh-muted">
                    {{ $overall['datasets'] }} datasets scored ·
                    {{ $overall['warning_count'] }} active warning(s)
                </div>
                <div class="dh-muted">
                    Last computed:
                    {{ $overall['last_computed'] ? \Illuminate\Support\Carbon::parse($overall['last_computed'])->diffForHumans() : 'never' }}
                </div>
            </div>
        </div>

        @if($snapshots->isEmpty())
            <div class="dh-card"><div class="dh-card-body dh-muted">No datasets have been scored yet. Use “Recompute” above once data has been ingested.</div></div>
        @else
            <div class="dh-grid">
                @foreach($snapshots as $snap)
                    @php $sc = $statusColors[$snap->status] ?? $statusColors['no_data']; @endphp
                    <div class="dh-card">
                        <div class="dh-card-head">
                            <span class="dh-card-title">{{ $snap->getDatasetLabel() }}</span>
                            <span class="dh-pill" style="background:{{ $sc[0] }}; color:{{ $sc[1] }};">
                                {{ $snap->score !== null ? number_format($snap->score,0) : '—' }} · {{ str_replace('_',' ', $snap->status) }}
                            </span>
                        </div>
                        <div class="dh-card-body">
                            <div class="dh-row"><span class="k">Last update</span><span class="v">{{ $snap->last_record_at ? $snap->last_record_at->diffForHumans() : '—' }}</span></div>
                            <div class="dh-row"><span class="k">Freshness</span><span class="v">{{ $snap->freshness_hours !== null ? $snap->freshness_hours.'h' : '—' }}</span></div>
                            <div class="dh-row"><span class="k">Received / Accepted / Rejected</span><span class="v">{{ number_format($snap->records_received) }} / {{ number_format($snap->records_accepted) }} / {{ number_format($snap->records_rejected) }}</span></div>

                            <div>
                                <div class="dh-row"><span class="k">Completeness</span><span class="v">{{ $snap->completeness_pct !== null ? $snap->completeness_pct.'%' : '—' }}</span></div>
                                <div class="dh-bar"><span style="width:{{ (float)($snap->completeness_pct ?? 0) }}%; background:#6366f1;"></span></div>
                            </div>
                            <div>
                                <div class="dh-row"><span class="k">Validity</span><span class="v">{{ $snap->validity_pct !== null ? $snap->validity_pct.'%' : '—' }}</span></div>
                                <div class="dh-bar"><span style="width:{{ (float)($snap->validity_pct ?? 0) }}%; background:#0ea5e9;"></span></div>
                            </div>

                            @if($snap->hasWarnings())
                                @foreach($snap->warnings as $w)
                                    <div class="dh-warn {{ $w['level'] ?? 'warning' }}">{{ $w['message'] ?? '' }}</div>
                                @endforeach
                            @endif

                            @php $affected = $this->affectedInvestigations($snap->dataset); @endphp
                            @if($affected->isNotEmpty() && in_array($snap->status, ['warning','critical','no_data']))
                                <div class="dh-affected">
                                    <strong>{{ $affected->count() }}</strong> open investigation(s) rely on this data and may be limited:
                                    {{ $affected->take(3)->map(fn($i) => \Illuminate\Support\Str::limit($i->title, 40))->implode('; ') }}
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-panels::page>
