{{-- Dashboard "operations pulse": detection status, transfer capacity, AI-narration
     coverage and recovery this month. Figures come from DashboardMetrics ($m) and
     RecoveryMetrics, the same numbers as the KPI cards (WP7.1). --}}
@php
    $opLastDetection   = \Filament\Facades\Filament::getTenant()?->last_detection_at;
    $opTransfer        = $m['pulse']['transfer'];
    $opNarratedPct     = $m['pulse']['narrated_pct'];
    $opRecoveryMtd     = (float) $m['kpi']['recovered_mtd'];
@endphp

<style>
.opp-grid{display:grid; grid-template-columns:repeat(4,1fr); gap:.7rem; margin-bottom:1.1rem;}
@media(max-width:820px){ .opp-grid{grid-template-columns:repeat(2,1fr);} }
.opp-tile{background:var(--ax-bg); border:1px solid var(--ax-line); border-radius:.8rem; box-shadow:var(--ax-shadow); padding:.85rem 1rem;}
.opp-lab{font-size:.66rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--ax-faint); display:flex; align-items:center; gap:.35rem;}
.opp-val{font-size:1.3rem; font-weight:800; color:var(--ax-ink); margin-top:.3rem; line-height:1.1;}
.opp-sub{font-size:.72rem; color:var(--ax-muted); margin-top:.2rem;}
.opp-dot{width:.5rem; height:.5rem; border-radius:9999px; display:inline-block;}
.opp-dot.ok{background:var(--ax-success);} .opp-dot.warn{background:var(--ax-warning);} .opp-dot.idle{background:var(--ax-faint);}
</style>

<div class="opp-grid">
    <div class="opp-tile">
        <div class="opp-lab">
            <span class="opp-dot {{ $opLastDetection && $opLastDetection->gt(now()->subDay()) ? 'ok' : ($opLastDetection ? 'warn' : 'idle') }}"></span>
            Detection
        </div>
        <div class="opp-val">{{ $opLastDetection ? $opLastDetection->diffForHumans(null, true) : '—' }}</div>
        <div class="opp-sub">{{ $opLastDetection ? 'since last run' : 'no run recorded yet' }}</div>
    </div>

    <div class="opp-tile" title="Units above order-up-to at some stores that other stores below their reorder point need, per SKU">
        <div class="opp-lab">🔁 Transfer capacity</div>
        <div class="opp-val">{{ number_format($opTransfer['units']) }}</div>
        <div class="opp-sub">
            @if($opTransfer['units'] > 0)
                units across {{ number_format($opTransfer['skus']) }} SKU{{ $opTransfer['skus'] === 1 ? '' : 's' }},
                {{ number_format($opTransfer['donors']) }} donor store{{ $opTransfer['donors'] === 1 ? '' : 's' }} → {{ number_format($opTransfer['receivers']) }} short
            @else
                no surplus that another store needs
            @endif
        </div>
    </div>

    <div class="opp-tile">
        <div class="opp-lab">🧠 AI-narrated</div>
        <div class="opp-val">{{ $opNarratedPct === null ? '—' : $opNarratedPct . '%' }}</div>
        <div class="opp-sub">of open investigations</div>
    </div>

    <div class="opp-tile">
        <div class="opp-lab">↩ Recovery (MTD)</div>
        <div class="opp-val">{{ \App\Support\Money::compact($opRecoveryMtd, $currency) }}</div>
        <div class="opp-sub">attributed, recorded this month</div>
    </div>
</div>
