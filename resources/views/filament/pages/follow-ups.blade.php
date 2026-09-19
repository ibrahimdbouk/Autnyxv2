<x-filament-panels::page>

@php($f = $this->getFollowUps())

<style>
.fu-hero{background:var(--ax-bg); border:1px solid var(--ax-line); border-radius:1rem; box-shadow:var(--ax-shadow); padding:1.3rem 1.6rem; margin-bottom:1.2rem; display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap;}
.fu-hero .lead{font-size:.92rem; color:var(--ax-muted); max-width:60ch; line-height:1.55;}
.fu-hero .lead b{color:var(--ax-ink);}
.fu-btn{font-size:.82rem; font-weight:700; padding:.5rem .95rem; border-radius:.55rem; border:1px solid transparent; cursor:pointer; background:var(--ax-accent-strong); color:#fff; white-space:nowrap;}
.fu-btn:disabled{opacity:.55; cursor:progress;}
.fu-list{display:flex; flex-direction:column; gap:.6rem;}
.fu-row{display:grid; grid-template-columns:auto 1fr auto; gap:.9rem; align-items:start; background:var(--ax-bg); border:1px solid var(--ax-line); border-radius:.75rem; box-shadow:var(--ax-shadow); padding:.85rem 1rem;}
a.fu-row{color:inherit; text-decoration:none;} a.fu-row:hover{border-color:var(--ax-accent-strong);}
.fu-pip{width:.6rem; height:.6rem; border-radius:50%; margin-top:.4rem;}
.fu-pip.recovered{background:var(--ax-accent-strong);} .fu-pip.working{background:#2F855A;}
.fu-pip.stalled{background:#C23B2E;} .fu-pip.no_signal{background:var(--ax-faint);}
.fu-row .t{font-size:.9rem; font-weight:700; color:var(--ax-ink);}
.fu-row .note{font-size:.84rem; color:var(--ax-muted); margin-top:.2rem; line-height:1.5;}
.fu-row .sub{font-size:.74rem; color:var(--ax-faint); margin-top:.3rem;}
.fu-tag{font-size:.66rem; font-weight:800; text-transform:uppercase; letter-spacing:.04em; padding:.2rem .5rem; border-radius:9999px; white-space:nowrap;}
.fu-tag.recovered{background:var(--ax-panel); color:var(--ax-accent-strong);} .fu-tag.working{background:var(--ax-panel); color:#2F855A;}
.fu-tag.stalled{background:#C23B2E; color:#fff;} .fu-tag.no_signal{background:var(--ax-panel); color:var(--ax-faint);}
.fu-esc{display:inline-block; font-size:.66rem; font-weight:800; color:#C23B2E; margin-top:.35rem;}
.fu-r{ text-align:right; display:flex; flex-direction:column; align-items:flex-end; gap:.35rem;}
.fu-v{font-size:.82rem; font-weight:700; color:var(--ax-ink);}
.fu-empty{background:var(--ax-bg); border:1px solid var(--ax-line); border-radius:1rem; box-shadow:var(--ax-shadow); padding:2rem 1.6rem; text-align:center;}
.fu-empty p{font-size:.9rem; color:var(--ax-muted); margin:0 auto 1rem; max-width:54ch; line-height:1.55;}
.fu-note{margin-top:1.3rem; font-size:.74rem; color:var(--ax-faint); max-width:80ch; line-height:1.55;}
</style>

@if(! ($f['ready'] ?? false))
    <div class="fu-empty"><p>Select a tenant to see its follow-ups.</p></div>
@elseif(empty($f['rows']))
    <div class="fu-empty">
        <p>No follow-ups yet. Once investigations have been actioned, Autnyx checks whether each fix is working and drafts a short note — flagging any that have stalled.</p>
        <button class="fu-btn" wire:click="run" wire:loading.attr="disabled" wire:target="run">
            <span wire:loading.remove wire:target="run">Run follow-ups now</span>
            <span wire:loading wire:target="run">Checking&hellip;</span>
        </button>
    </div>
@else
    <div class="fu-hero">
        <div class="lead">Autnyx checked each actioned investigation and drafted a &ldquo;did it work?&rdquo; note. <b>{{ $f['escalate'] }}</b> recommend escalation. It recommends only — nothing is reopened automatically.</div>
        <button class="fu-btn" wire:click="run" wire:loading.attr="disabled" wire:target="run">
            <span wire:loading.remove wire:target="run">Re-run follow-ups</span>
            <span wire:loading wire:target="run">Checking&hellip;</span>
        </button>
    </div>

    <div class="fu-list">
        @foreach($f['rows'] as $r)
        <a class="fu-row" @if($r['url']) href="{{ $r['url'] }}" @endif>
            <span class="fu-pip {{ $r['status'] }}"></span>
            <div>
                <div class="t">{{ $r['title'] }}</div>
                @if(!empty($r['note']))<div class="note">{{ $r['note'] }}</div>@endif
                <div class="sub">{{ $r['product'] }}@if(!empty($r['ago'])) &middot; {{ $r['ago'] }}@endif @if(!empty($r['next'])) &middot; next check in {{ $r['next'] }}d @endif</div>
                @if($r['escalate'])<span class="fu-esc">&#9650; Recommend escalation</span>@endif
            </div>
            <div class="fu-r">
                <span class="fu-tag {{ $r['status'] }}">{{ str_replace('_',' ',$r['status']) }}</span>
                @if(!empty($r['at_risk']))<span class="fu-v">{{ $r['at_risk'] }}</span>@endif
            </div>
        </a>
        @endforeach
    </div>

    <div class="fu-note">
        Each note is drawn from deterministic recovery signals — observed recovery, outcome state, and whether the item is still flagging. Autnyx recommends; a person decides whether to escalate.
    </div>
@endif

</x-filament-panels::page>
