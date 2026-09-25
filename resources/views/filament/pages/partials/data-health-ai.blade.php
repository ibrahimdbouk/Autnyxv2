{{-- WP7.4 (D12): the AI data-quality verdict, a section of the Data Health page (was its own "Data Quality" page). --}}
<section id="ai-check" class="dh-section">
<h2 class="dh-section-title">AI data-quality check</h2>
@php($r = $this->getReport())

<style>
.dh-hero{background:var(--ax-bg); border:1px solid var(--ax-line); border-radius:1rem; box-shadow:var(--ax-shadow); padding:1.4rem 1.6rem; margin-bottom:1.2rem;}
.dh-kick{font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:var(--ax-accent-strong); display:flex; align-items:center; gap:.5rem; flex-wrap:wrap;}
.dh-h{font-size:1.25rem; font-weight:800; color:var(--ax-ink); margin:.4rem 0 0; line-height:1.3; max-width:56ch;}
.dh-sum{font-size:.9rem; color:var(--ax-muted); margin:.5rem 0 0; max-width:74ch; line-height:1.6;}
.dh-ready{font-size:.7rem; font-weight:800; padding:.22rem .6rem; border-radius:9999px; text-transform:uppercase; letter-spacing:.04em; color:#fff;}
.dh-ready.good{background:var(--ax-accent-strong);} .dh-ready.fair{background:#B67608;} .dh-ready.poor{background:#C23B2E;}
.dh-meta{font-size:.74rem; color:var(--ax-faint); margin-left:auto;}
.dh-checks{display:grid; grid-template-columns:repeat(4,1fr); gap:.7rem; margin:1.1rem 0 1.3rem;}
@media(max-width:720px){ .dh-checks{grid-template-columns:repeat(2,1fr);} }
.dh-chk{background:var(--ax-bg); border:1px solid var(--ax-line); border-radius:.7rem; box-shadow:var(--ax-shadow); padding:.75rem .9rem;}
.dh-chk .n{font-size:1.35rem; font-weight:800; color:var(--ax-ink); line-height:1;}
.dh-chk .l{font-size:.72rem; color:var(--ax-faint); margin-top:.3rem;}
.dh-sec{font-size:1rem; font-weight:700; color:var(--ax-ink); margin:1.4rem 0 .7rem;}
.dh-issue{display:grid; grid-template-columns:auto 1fr; gap:.85rem; background:var(--ax-bg); border:1px solid var(--ax-line); border-radius:.75rem; box-shadow:var(--ax-shadow); padding:.85rem 1rem; margin-bottom:.6rem;}
.dh-sev{width:.6rem; height:.6rem; border-radius:50%; margin-top:.4rem;}
.dh-sev.high{background:#C23B2E;} .dh-sev.medium{background:#B67608;} .dh-sev.low{background:var(--ax-faint);}
.dh-issue .area{font-size:.9rem; font-weight:700; color:var(--ax-ink);}
.dh-issue .finding{font-size:.86rem; color:var(--ax-ink); margin-top:.15rem; line-height:1.5;}
.dh-issue .impact{font-size:.8rem; color:var(--ax-muted); margin-top:.3rem; line-height:1.45;}
.dh-issue .fix{font-size:.82rem; color:var(--ax-ink); background:var(--ax-panel); border-radius:.45rem; padding:.4rem .6rem; margin-top:.45rem; line-height:1.45;}
.dh-btn{font-size:.82rem; font-weight:700; padding:.5rem .95rem; border-radius:.55rem; border:1px solid transparent; cursor:pointer; background:var(--ax-accent-strong); color:#fff;}
.dh-btn:disabled{opacity:.55; cursor:progress;}
.dh-empty{background:var(--ax-bg); border:1px solid var(--ax-line); border-radius:1rem; box-shadow:var(--ax-shadow); padding:2rem 1.6rem; text-align:center;}
.dh-empty p{font-size:.9rem; color:var(--ax-muted); margin:0 auto 1rem; max-width:54ch; line-height:1.55;}
.dh-note{margin-top:1.3rem; font-size:.74rem; color:var(--ax-faint); max-width:80ch; line-height:1.55;}
</style>

@if(! ($r['ready'] ?? false))
@elseif(! ($r['has'] ?? false))
    <div class="dh-empty">
        <p>No data-quality check yet. Run one now — Autnyx will review completeness and freshness across your products, inventory, suppliers and imports, and flag anything that would distort detection.</p>
        <button class="dh-btn" wire:click="run" wire:loading.attr="disabled" wire:target="run">
            <span wire:loading.remove wire:target="run">Run data-quality check</span>
            <span wire:loading wire:target="run">Checking&hellip;</span>
        </button>
    </div>
@else
    <div class="dh-hero">
        <div class="dh-kick">AI data-quality check
            @if(!empty($r['readiness']))<span class="dh-ready {{ $r['readiness'] }}">{{ $r['readiness'] }}</span>@endif
            @if(!empty($r['confidence']))<span style="font-size:.68rem;color:var(--ax-faint);text-transform:capitalize;">{{ $r['confidence'] }} confidence</span>@endif
            @if(!empty($r['generated_ago']))<span class="dh-meta">checked {{ $r['generated_ago'] }}</span>@endif
        </div>
        @if(!empty($r['failed']))
            <div class="dh-h">The check could not be completed</div>
            <div class="dh-sum">The AI service did not respond last time. Try running it again.</div>
        @else
            @if(!empty($r['headline']))<div class="dh-h">{{ $r['headline'] }}</div>@endif
            @if(!empty($r['summary']))<div class="dh-sum">{{ $r['summary'] }}</div>@endif
        @endif
    </div>

    @if(!empty($r['checks']))
    @php($c = $r['checks'])
    <div class="dh-checks">
        <div class="dh-chk"><div class="n">{{ $c['products_missing_cost_pct'] ?? 0 }}%</div><div class="l">products missing unit cost</div></div>
        <div class="dh-chk"><div class="n">{{ $c['inventory_stale_days'] ?? '—' }}</div><div class="l">days since latest inventory</div></div>
        <div class="dh-chk"><div class="n">{{ $c['suppliers_missing_lead_time'] ?? 0 }}</div><div class="l">suppliers missing lead time</div></div>
        <div class="dh-chk"><div class="n">{{ $c['po_missing_expected_date'] ?? 0 }}</div><div class="l">POs missing expected date</div></div>
    </div>
    @endif

    @if(!empty($r['issues']))
    <div class="dh-sec">Issues to fix</div>
    @foreach($r['issues'] as $i)
    <div class="dh-issue">
        <span class="dh-sev {{ $i['severity'] ?? 'low' }}"></span>
        <div>
            @if(!empty($i['area']))<div class="area">{{ $i['area'] }}</div>@endif
            @if(!empty($i['finding']))<div class="finding">{{ $i['finding'] }}</div>@endif
            @if(!empty($i['impact']))<div class="impact">Impact: {{ $i['impact'] }}</div>@endif
            @if(!empty($i['fix']))<div class="fix">Fix: {{ $i['fix'] }}</div>@endif
        </div>
    </div>
    @endforeach
    @endif

    <div style="margin-top:1.2rem; display:flex; gap:.6rem; align-items:center;">
        <button class="dh-btn" wire:click="run" wire:loading.attr="disabled" wire:target="run">
            <span wire:loading.remove wire:target="run">Re-run check</span>
            <span wire:loading wire:target="run">Checking&hellip;</span>
        </button>
        @if(!empty($r['generated_at']))<span class="dh-meta" style="margin:0">Checked {{ $r['generated_at'] }}</span>@endif
    </div>

    <div class="dh-note">
        Every count above is a deterministic check over this tenant&rsquo;s own data. Autnyx flags what looks weak; it never edits your data. Fixing the high-severity items first makes detection results more trustworthy.
    </div>
@endif
</section>
