<x-filament-panels::page>

@php($b = $this->getBriefing())

<style>
.wb-hero{background:var(--ax-bg); border:1px solid var(--ax-line); border-radius:1rem; box-shadow:var(--ax-shadow); padding:1.4rem 1.6rem; margin-bottom:1.2rem;}
.wb-kick{font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:var(--ax-accent-strong); display:flex; align-items:center; gap:.5rem; flex-wrap:wrap;}
.wb-h{font-size:1.3rem; font-weight:800; color:var(--ax-ink); margin:.4rem 0 0; line-height:1.3; max-width:52ch;}
.wb-sum{font-size:.92rem; color:var(--ax-muted); margin:.55rem 0 0; max-width:74ch; line-height:1.6;}
.wb-conf{font-size:.68rem; font-weight:700; padding:.2rem .55rem; border-radius:9999px; background:var(--ax-panel); color:var(--ax-muted); text-transform:capitalize;}
.wb-meta{font-size:.74rem; color:var(--ax-faint); margin-left:auto;}
.wb-stats{display:grid; grid-template-columns:repeat(4,1fr); gap:.7rem; margin:1.1rem 0 1.3rem;}
@media(max-width:720px){ .wb-stats{grid-template-columns:repeat(2,1fr);} }
.wb-stat{background:var(--ax-bg); border:1px solid var(--ax-line); border-radius:.7rem; box-shadow:var(--ax-shadow); padding:.8rem .9rem;}
.wb-stat .n{font-size:1.5rem; font-weight:800; color:var(--ax-ink); line-height:1;}
.wb-stat .l{font-size:.72rem; color:var(--ax-faint); margin-top:.3rem;}
.wb-grid{display:grid; grid-template-columns:repeat(2,1fr); gap:.9rem;}
@media(max-width:720px){ .wb-grid{grid-template-columns:1fr;} }
.wb-card{background:var(--ax-bg); border:1px solid var(--ax-line); border-radius:.85rem; box-shadow:var(--ax-shadow); padding:1rem 1.15rem;}
.wb-card h4{margin:0 0 .55rem; font-size:.78rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--ax-accent-strong);}
.wb-card ul{margin:0; padding-left:1.15rem; display:flex; flex-direction:column; gap:.4rem;}
.wb-card li{font-size:.87rem; color:var(--ax-ink); line-height:1.5;}
.wb-btn{font-size:.82rem; font-weight:700; padding:.5rem .95rem; border-radius:.55rem; border:1px solid transparent; cursor:pointer; background:var(--ax-accent-strong); color:#fff;}
.wb-btn:disabled{opacity:.55; cursor:progress;}
.wb-btn-ghost{background:transparent; border-color:var(--ax-line); color:var(--ax-muted);}
.wb-empty{background:var(--ax-bg); border:1px solid var(--ax-line); border-radius:1rem; box-shadow:var(--ax-shadow); padding:2rem 1.6rem; text-align:center;}
.wb-empty p{font-size:.9rem; color:var(--ax-muted); margin:0 auto 1rem; max-width:52ch; line-height:1.55;}
.wb-note{margin-top:1.3rem; font-size:.74rem; color:var(--ax-faint); max-width:80ch; line-height:1.55;}
</style>

@if(! ($b['ready'] ?? false))
    <div class="wb-empty"><p>Select a tenant to see its weekly briefing.</p></div>
@elseif(! ($b['has'] ?? false))
    <div class="wb-empty">
        <p>No briefing yet for this week. Generate one now — Autnyx will summarise the last seven days of movement across your investigations.</p>
        <button class="wb-btn" wire:click="generate" wire:loading.attr="disabled" wire:target="generate">
            <span wire:loading.remove wire:target="generate">Generate this week&rsquo;s briefing</span>
            <span wire:loading wire:target="generate">Generating&hellip;</span>
        </button>
    </div>
@else
    <div class="wb-hero">
        <div class="wb-kick">Weekly briefing
            @if(!empty($b['confidence']))<span class="wb-conf">{{ $b['confidence'] }} confidence</span>@endif
            @if(!empty($b['generated_ago']))<span class="wb-meta">updated {{ $b['generated_ago'] }}</span>@endif
        </div>
        @if(!empty($b['failed']))
            <div class="wb-sum"><strong>The latest briefing could not be generated.</strong>
                {{ !empty($b['last_good_ago']) ? 'Showing the last good one, from ' . $b['last_good_ago'] . '.' : 'The AI service did not respond. Try generating it again.' }}</div>
        @endif
        @if(!empty($b['headline']))<div class="wb-h">{{ $b['headline'] }}</div>@endif
        @if(!empty($b['summary']))<div class="wb-sum">{{ $b['summary'] }}</div>@endif
    </div>

    @if(!empty($b['stats']))
    @php($s = $b['stats'])
    <div class="wb-stats">
        <div class="wb-stat"><div class="n">{{ $s['opened'] ?? 0 }}</div><div class="l">opened this week &middot; {{ $s['opened_value_fmt'] ?? '' }}</div></div>
        <div class="wb-stat"><div class="n">{{ $s['resolved'] ?? 0 }}</div><div class="l">resolved this week</div></div>
        <div class="wb-stat"><div class="n">{{ $s['recovery_fmt'] ?? '' }}</div><div class="l">recovery recorded</div></div>
        <div class="wb-stat"><div class="n">{{ $s['open_now'] ?? 0 }}</div><div class="l">open now &middot; {{ $s['open_value_fmt'] ?? '' }} at risk</div></div>
    </div>
    @endif

    <div class="wb-grid">
        @if(!empty($b['whats_new']))
        <div class="wb-card"><h4>What&rsquo;s new</h4><ul>@foreach($b['whats_new'] as $x)<li>{{ $x }}</li>@endforeach</ul></div>
        @endif
        @if(!empty($b['whats_recovering']))
        <div class="wb-card"><h4>What&rsquo;s recovering</h4><ul>@foreach($b['whats_recovering'] as $x)<li>{{ $x }}</li>@endforeach</ul></div>
        @endif
        @if(!empty($b['where_money_moved']))
        <div class="wb-card"><h4>Where the money moved</h4><ul>@foreach($b['where_money_moved'] as $x)<li>{{ $x }}</li>@endforeach</ul></div>
        @endif
        @if(!empty($b['focus_next_week']))
        <div class="wb-card"><h4>Focus next week</h4><ul>@foreach($b['focus_next_week'] as $x)<li>{{ $x }}</li>@endforeach</ul></div>
        @endif
    </div>

    <div style="margin-top:1.2rem; display:flex; gap:.6rem; align-items:center;">
        <button class="wb-btn" wire:click="generate" wire:loading.attr="disabled" wire:target="generate">
            <span wire:loading.remove wire:target="generate">Regenerate</span>
            <span wire:loading wire:target="generate">Generating&hellip;</span>
        </button>
        @if(!empty($b['generated_at']))<span class="wb-meta" style="margin:0">Generated {{ $b['generated_at'] }}</span>@endif
    </div>

    <div class="wb-note">
        Every figure above is a deterministic aggregate over this tenant&rsquo;s investigations and active anomalies for the last seven days. Autnyx narrates the week; it does not decide or act.
    </div>
@endif

</x-filament-panels::page>
