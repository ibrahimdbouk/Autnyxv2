<x-filament-panels::page>

@php($d = $this->getData())

<style>
.sp-back{display:inline-block; font-size:.8rem; font-weight:700; color:var(--ax-accent-strong); text-decoration:none; margin-bottom:.5rem;}
.sp-back:hover{text-decoration:underline;}
.sp-hero{background:var(--ax-bg); border:1px solid var(--ax-line); border-radius:1rem; box-shadow:var(--ax-shadow); padding:1.3rem 1.6rem; margin-bottom:1.2rem;}
.sp-kick{font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:var(--ax-accent-strong); display:flex; align-items:center; gap:.5rem; flex-wrap:wrap;}
.sp-h{font-size:1.25rem; font-weight:800; color:var(--ax-ink); margin:.35rem 0 0; line-height:1.3; max-width:56ch;}
.sp-sum{font-size:.9rem; color:var(--ax-muted); margin:.5rem 0 0; max-width:74ch; line-height:1.6;}
.sp-meta{font-size:.74rem; color:var(--ax-faint); margin-left:auto;}
.sp-scores{display:grid; grid-template-columns:repeat(4,1fr); gap:.7rem; margin:1.1rem 0 1.3rem;}
@media(max-width:720px){ .sp-scores{grid-template-columns:repeat(2,1fr);} }
.sp-score{background:var(--ax-bg); border:1px solid var(--ax-line); border-radius:.7rem; box-shadow:var(--ax-shadow); padding:.75rem .9rem;}
.sp-score .n{font-size:1.35rem; font-weight:800; color:var(--ax-ink); line-height:1;}
.sp-score .l{font-size:.72rem; color:var(--ax-faint); margin-top:.3rem;}
.sp-grid{display:grid; grid-template-columns:repeat(2,1fr); gap:.9rem;}
@media(max-width:720px){ .sp-grid{grid-template-columns:1fr;} }
.sp-card{background:var(--ax-bg); border:1px solid var(--ax-line); border-radius:.85rem; box-shadow:var(--ax-shadow); padding:1rem 1.15rem;}
.sp-card h4{margin:0 0 .55rem; font-size:.78rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--ax-accent-strong);}
.sp-card ul{margin:0; padding-left:1.15rem; display:flex; flex-direction:column; gap:.4rem;}
.sp-card li{font-size:.87rem; color:var(--ax-ink); line-height:1.5;}
.sp-btn{font-size:.82rem; font-weight:700; padding:.5rem .95rem; border-radius:.55rem; border:1px solid transparent; cursor:pointer; background:var(--ax-accent-strong); color:#fff;}
.sp-btn:disabled{opacity:.55; cursor:progress;}
.sp-list{display:flex; flex-direction:column; gap:.5rem;}
.sp-row{display:grid; grid-template-columns:1fr auto auto; gap:.9rem; align-items:center; background:var(--ax-bg); border:1px solid var(--ax-line); border-radius:.7rem; box-shadow:var(--ax-shadow); padding:.75rem .95rem; text-decoration:none; color:inherit;}
.sp-row:hover{border-color:var(--ax-accent-strong);}
.sp-row .t{font-weight:700; font-size:.9rem; color:var(--ax-ink);}
.sp-row .s{font-size:.74rem; color:var(--ax-faint); margin-top:1px;}
.sp-row .c{font-size:.78rem; color:var(--ax-muted); white-space:nowrap;}
.sp-row .go{font-size:.76rem; font-weight:700; color:var(--ax-accent-strong); white-space:nowrap;}
.sp-empty{background:var(--ax-bg); border:1px solid var(--ax-line); border-radius:1rem; box-shadow:var(--ax-shadow); padding:2rem 1.6rem; text-align:center;}
.sp-empty p{font-size:.9rem; color:var(--ax-muted); margin:0 auto 1rem; max-width:54ch; line-height:1.55;}
.sp-note{margin-top:1.3rem; font-size:.74rem; color:var(--ax-faint); max-width:80ch; line-height:1.55;}
</style>

@if(! ($d['ready'] ?? false))
    <div class="sp-empty"><p>Select a tenant to prepare supplier briefs.</p></div>

@elseif(($d['mode'] ?? '') === 'list')
    <div class="sp-hero">
        <div class="sp-kick">Supplier prep</div>
        <div class="sp-h">Walk into supplier conversations with the evidence</div>
        <div class="sp-sum">Pick a supplier — Autnyx assembles a negotiation brief from their PO scorecard (lead time, fill rate, lateness) and the supply issues tied to their SKUs.</div>
    </div>
    @if(empty($d['suppliers']))
        <div class="sp-empty"><p>No suppliers on file for this tenant yet. Import suppliers and purchase orders to build negotiation packs.</p></div>
    @else
    <div class="sp-list">
        @foreach($d['suppliers'] as $s)
        <a class="sp-row" href="{{ $s['url'] }}">
            <div>
                <div class="t">{{ $s['name'] }}</div>
                <div class="s">{{ $s['specialization'] ?: 'General' }}@if(!empty($s['lead'])) &middot; {{ $s['lead'] }}d contracted lead @endif</div>
            </div>
            <div class="c">{{ $s['pos'] }} POs</div>
            <div class="go">Prepare &rarr;</div>
        </a>
        @endforeach
    </div>
    @endif

@else
    {{-- detail --}}
    <a href="{{ $d['back_url'] }}" class="sp-back">&larr; All suppliers</a>

    @if(! ($d['has'] ?? false))
        <div class="sp-hero">
            <div class="sp-kick">Supplier prep</div>
            <div class="sp-h">{{ $d['name'] }}</div>
            <div class="sp-sum">No pack yet. Build one — Autnyx will assemble the scorecard and draft talking points, asks and leverage for your next conversation.</div>
            <div style="margin-top:1rem">
                <button class="sp-btn" wire:click="build" wire:loading.attr="disabled" wire:target="build">
                    <span wire:loading.remove wire:target="build">Build negotiation pack</span>
                    <span wire:loading wire:target="build">Building&hellip;</span>
                </button>
            </div>
        </div>
    @elseif($d['failed'])
        <div class="sp-hero">
            <div class="sp-kick">Supplier prep</div>
            <div class="sp-h">{{ $d['name'] }}</div>
            <div class="sp-sum">The pack could not be built last time. Try again.</div>
            <div style="margin-top:1rem">
                <button class="sp-btn" wire:click="build" wire:loading.attr="disabled" wire:target="build">
                    <span wire:loading.remove wire:target="build">Try again</span>
                    <span wire:loading wire:target="build">Building&hellip;</span>
                </button>
            </div>
        </div>
    @else
        <div class="sp-hero">
            <div class="sp-kick">Negotiation brief
                @if(!empty($d['confidence']))<span style="font-size:.68rem;color:var(--ax-faint);text-transform:capitalize;">{{ $d['confidence'] }} confidence</span>@endif
                @if(!empty($d['generated_ago']))<span class="sp-meta">built {{ $d['generated_ago'] }}</span>@endif
            </div>
            <div class="sp-h">{{ $d['name'] }}</div>
            @if(!empty($d['headline']))<div class="sp-sum" style="font-weight:700;color:var(--ax-ink)">{{ $d['headline'] }}</div>@endif
            @if(!empty($d['summary']))<div class="sp-sum">{{ $d['summary'] }}</div>@endif
        </div>

        @php($p = $d['pack'] ?? [])
        @if(!empty($p))
        <div class="sp-scores">
            <div class="sp-score"><div class="n">{{ $p['late_pct'] ?? 0 }}%</div><div class="l">POs late ({{ $p['avg_late_days'] ?? 0 }}d avg)</div></div>
            <div class="sp-score"><div class="n">{{ isset($p['avg_fill_rate']) ? $p['avg_fill_rate'].'%' : '—' }}</div><div class="l">average fill rate</div></div>
            <div class="sp-score"><div class="n">{{ $p['open_pos'] ?? 0 }}</div><div class="l">open POs</div></div>
            <div class="sp-score"><div class="n">{{ $p['anomaly_value_fmt'] ?? '' }}</div><div class="l">supply risk at stake</div></div>
        </div>
        @endif

        <div class="sp-grid">
            @if(!empty($d['talking_points']))
            <div class="sp-card"><h4>Talking points</h4><ul>@foreach($d['talking_points'] as $x)<li>{{ $x }}</li>@endforeach</ul></div>
            @endif
            @if(!empty($d['asks']))
            <div class="sp-card"><h4>Asks</h4><ul>@foreach($d['asks'] as $x)<li>{{ $x }}</li>@endforeach</ul></div>
            @endif
            @if(!empty($d['leverage']))
            <div class="sp-card"><h4>Leverage</h4><ul>@foreach($d['leverage'] as $x)<li>{{ $x }}</li>@endforeach</ul></div>
            @endif
            @if(!empty($d['data_points']))
            <div class="sp-card"><h4>Numbers to have on hand</h4><ul>@foreach($d['data_points'] as $x)<li>{{ $x }}</li>@endforeach</ul></div>
            @endif
        </div>

        <div style="margin-top:1.2rem">
            <button class="sp-btn" wire:click="build" wire:loading.attr="disabled" wire:target="build">
                <span wire:loading.remove wire:target="build">Rebuild pack</span>
                <span wire:loading wire:target="build">Building&hellip;</span>
            </button>
        </div>

        <div class="sp-note">
            The scorecard is a deterministic rollup of this supplier&rsquo;s purchase orders and the active supply anomalies on their SKUs. Autnyx drafts the brief; you run the negotiation.
        </div>
    @endif
@endif

</x-filament-panels::page>
