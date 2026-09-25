<x-filament-panels::page>

@php($b = $this->getBriefing())

<style>
.db-hero{background:var(--ax-bg); border:1px solid var(--ax-line); border-radius:1rem; box-shadow:var(--ax-shadow); padding:1.4rem 1.6rem; margin-bottom:1.2rem;}
.db-kick{font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:var(--ax-accent-strong); display:flex; align-items:center; gap:.5rem; flex-wrap:wrap;}
.db-h{font-size:1.3rem; font-weight:800; color:var(--ax-ink); margin:.4rem 0 0; line-height:1.3; max-width:52ch;}
.db-sum{font-size:.92rem; color:var(--ax-muted); margin:.55rem 0 0; max-width:74ch; line-height:1.6;}
.db-conf{font-size:.68rem; font-weight:700; padding:.2rem .55rem; border-radius:9999px; background:var(--ax-panel); color:var(--ax-muted); text-transform:capitalize;}
.db-meta{font-size:.74rem; color:var(--ax-faint); margin-left:auto;}
.db-stats{display:grid; grid-template-columns:repeat(4,1fr); gap:.7rem; margin:1.1rem 0 1.3rem;}
@media(max-width:720px){ .db-stats{grid-template-columns:repeat(2,1fr);} }
.db-stat{background:var(--ax-bg); border:1px solid var(--ax-line); border-radius:.7rem; box-shadow:var(--ax-shadow); padding:.8rem .9rem;}
.db-stat .n{font-size:1.5rem; font-weight:800; color:var(--ax-ink); line-height:1;}
.db-stat .n.warn{color:#b45309;}
.db-stat .l{font-size:.72rem; color:var(--ax-faint); margin-top:.3rem;}
.db-grid{display:grid; grid-template-columns:repeat(3,1fr); gap:.9rem;}
@media(max-width:900px){ .db-grid{grid-template-columns:1fr;} }
.db-card{background:var(--ax-bg); border:1px solid var(--ax-line); border-radius:.85rem; box-shadow:var(--ax-shadow); padding:1rem 1.15rem;}
.db-card h4{margin:0 0 .55rem; font-size:.78rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--ax-accent-strong);}
.db-card ul{margin:0; padding-left:1.15rem; display:flex; flex-direction:column; gap:.4rem;}
.db-card li{font-size:.87rem; color:var(--ax-ink); line-height:1.5;}
.db-btn{background:var(--ax-accent-strong); color:#fff; border:0; border-radius:.6rem; padding:.55rem 1rem; font-weight:700; font-size:.85rem; cursor:pointer;}
.db-empty{background:var(--ax-bg); border:1px dashed var(--ax-line); border-radius:1rem; padding:2rem; text-align:center; color:var(--ax-muted);}
.db-note{font-size:.76rem; color:var(--ax-faint); margin-top:1.1rem; line-height:1.55; max-width:80ch;}
</style>

@if(! ($b['ready'] ?? false))
    <div class="db-empty"><p>Select a tenant to see its daily briefing.</p></div>
@elseif(! ($b['has'] ?? false))
    <div class="db-empty">
        <p>No briefing yet for today. Generate one now — Autnyx will summarise what landed overnight and what needs a decision today.</p>
        <button class="db-btn" style="margin-top:1rem" wire:click="generate" wire:loading.attr="disabled" wire:target="generate">
            <span wire:loading.remove wire:target="generate">Generate today&rsquo;s briefing</span>
            <span wire:loading wire:target="generate">Generating&hellip;</span>
        </button>
    </div>
@else
    <div class="db-hero">
        <div class="db-kick">Daily briefing
            @if(!empty($b['confidence']))<span class="db-conf">{{ $b['confidence'] }} confidence</span>@endif
            @if(!empty($b['generated_ago']))<span class="db-meta">updated {{ $b['generated_ago'] }}</span>@endif
        </div>
        @if(!empty($b['failed']))
            <div class="db-sum"><strong>The latest briefing could not be generated.</strong>
                {{ !empty($b['last_good_ago']) ? 'Showing the last good one, from ' . $b['last_good_ago'] . '.' : 'The AI service did not respond. Try generating it again.' }}</div>
        @endif
        @if(!empty($b['headline']))<div class="db-h">{{ $b['headline'] }}</div>@endif
        @if(!empty($b['summary']))<div class="db-sum">{{ $b['summary'] }}</div>@endif
    </div>

    @if(!empty($b['stats']))
    @php($s = $b['stats'])
    <div class="db-stats">
        <div class="db-stat"><div class="n">{{ $s['new_signals'] ?? 0 }}</div><div class="l">new signals today</div></div>
        <div class="db-stat"><div class="n">{{ $s['opened_today'] ?? 0 }}</div><div class="l">opened today &middot; {{ $s['opened_today_value_fmt'] ?? '' }}</div></div>
        <div class="db-stat"><div class="n {{ (($s['overdue'] ?? 0) > 0) ? 'warn' : '' }}">{{ $s['due_today'] ?? 0 }} / {{ $s['overdue'] ?? 0 }}</div><div class="l">due today / overdue</div></div>
        <div class="db-stat"><div class="n {{ (($s['blocked_feeds'] ?? 0) > 0) ? 'warn' : '' }}">{{ $s['blocked_feeds'] ?? 0 }}</div><div class="l">feeds blocked now</div></div>
    </div>
    @endif

    <div class="db-grid">
        @if(!empty($b['overnight']))
        <div class="db-card"><h4>Overnight</h4><ul>@foreach($b['overnight'] as $x)<li>{{ $x }}</li>@endforeach</ul></div>
        @endif
        @if(!empty($b['act_today']))
        <div class="db-card"><h4>Act today</h4><ul>@foreach($b['act_today'] as $x)<li>{{ $x }}</li>@endforeach</ul></div>
        @endif
        @if(!empty($b['watch']))
        <div class="db-card"><h4>Watch</h4><ul>@foreach($b['watch'] as $x)<li>{{ $x }}</li>@endforeach</ul></div>
        @endif
    </div>

    <div style="margin-top:1.2rem; display:flex; gap:.6rem; align-items:center;">
        <button class="db-btn" wire:click="generate" wire:loading.attr="disabled" wire:target="generate">
            <span wire:loading.remove wire:target="generate">Regenerate</span>
            <span wire:loading wire:target="generate">Generating&hellip;</span>
        </button>
        @if(!empty($b['generated_at']))<span class="db-meta" style="margin:0">Generated {{ $b['generated_at'] }}</span>@endif
    </div>

    <div class="db-note">
        Every figure above is a deterministic aggregate over this tenant&rsquo;s investigations, signals, actions and data feeds since the start of today. Autnyx narrates the day; it does not decide or act.
    </div>
@endif

</x-filament-panels::page>
