<x-filament-panels::page>

@php($q = $this->getQueue())

<style>
.aq-a-crit{--c:#C23B2E} .aq-a-warn{--c:#B67608} .aq-a-teal{--c:var(--ax-accent-strong)}
@media (prefers-color-scheme:dark){ .aq-a-crit{--c:#EF8A7E} .aq-a-warn{--c:#E7B24E} }

.aq-hero{background:var(--ax-bg); border:1px solid var(--ax-line); border-radius:1rem; box-shadow:var(--ax-shadow);
    padding:1.4rem 1.6rem; margin-bottom:1.4rem;}
.aq-hero-kick{font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:var(--ax-accent-strong);}
.aq-hero-h{font-size:1.35rem; font-weight:800; color:var(--ax-ink); margin:.3rem 0 0; line-height:1.25; max-width:36ch;}
.aq-hero-p{font-size:.9rem; color:var(--ax-muted); margin:.45rem 0 0; max-width:66ch; line-height:1.5;}
.aq-flow{display:flex; align-items:stretch; gap:.6rem; margin-top:1.1rem; flex-wrap:wrap;}
.aq-step{flex:1 1 140px; background:var(--ax-panel); border:1px solid var(--ax-line); border-radius:.7rem; padding:.7rem .85rem;}
.aq-step .n{font-size:1.7rem; font-weight:800; line-height:1; color:var(--ax-ink);}
.aq-step .l{font-size:.72rem; color:var(--ax-faint); margin-top:.25rem;}
.aq-arrow{align-self:center; color:var(--ax-faint);}
@media(max-width:640px){ .aq-arrow{display:none} }

.aq-sec{margin:1.6rem 0 .7rem; display:flex; align-items:baseline; justify-content:space-between; gap:.75rem;}
.aq-sec h3{font-size:1rem; font-weight:700; color:var(--ax-ink); margin:0;}
.aq-sec .m{font-size:.75rem; color:var(--ax-faint);}

.aq-queue{display:flex; flex-direction:column; gap:.5rem;}
.aq-row{display:grid; grid-template-columns:auto 1fr auto auto; align-items:center; gap:.85rem;
    background:var(--ax-bg); border:1px solid var(--ax-line); border-radius:.7rem; padding:.7rem .95rem; box-shadow:var(--ax-shadow);}
.aq-pip{width:.55rem; height:.55rem; border-radius:50%;}
.aq-pip.high{background:#C23B2E} .aq-pip.medium{background:#B67608} .aq-pip.low{background:var(--ax-faint)}
.aq-row .t{font-weight:600; font-size:.9rem; color:var(--ax-ink);}
.aq-row .s{font-size:.76rem; color:var(--ax-faint); margin-top:1px;}
.aq-row .v{font-weight:700; font-size:.9rem; color:var(--ax-ink); white-space:nowrap;}
.aq-row .b{font-size:.74rem; font-weight:700; color:var(--ax-accent-strong); border:1px solid var(--ax-line); padding:.32rem .6rem; border-radius:9999px; white-space:nowrap;}
@media(max-width:560px){ .aq-row{grid-template-columns:auto 1fr auto} .aq-row .b{grid-column:2/4; justify-self:start} }

.aq-grid{display:grid; grid-template-columns:repeat(2,1fr); gap:.85rem;}
@media(max-width:760px){ .aq-grid{grid-template-columns:1fr} }
.aq-card{position:relative; background:var(--ax-bg); border:1px solid var(--ax-line); border-radius:.85rem; box-shadow:var(--ax-shadow);
    padding:1rem 1.05rem 0.9rem 1.15rem; display:flex; flex-direction:column; gap:.7rem;}
.aq-card::before{content:""; position:absolute; left:0; top:1rem; bottom:1rem; width:3px; border-radius:3px; background:var(--c);}
.aq-card-h{display:flex; align-items:flex-start; justify-content:space-between; gap:.75rem;}
.aq-card-h h4{margin:0; font-size:.98rem; font-weight:700; color:var(--ax-ink);}
.aq-card-h .cnt{font-size:1.6rem; font-weight:800; line-height:.9; color:var(--ax-ink); text-align:right;}
.aq-card-h .cnt small{display:block; font-size:.66rem; font-weight:600; color:var(--ax-faint); letter-spacing:.02em;}
.aq-card .act{font-size:.82rem; color:var(--ax-muted);}
.aq-ex{display:flex; align-items:center; justify-content:space-between; gap:.6rem; font-size:.78rem;
    background:var(--ax-panel); border-radius:.45rem; padding:.35rem .55rem;}
.aq-ex .sk{font-weight:600; color:var(--ax-ink)} .aq-ex .lo{color:var(--ax-faint); font-size:.72rem}
.aq-ex .ev{font-weight:700; color:var(--ax-muted); white-space:nowrap}
.aq-card-f{display:flex; align-items:center; justify-content:space-between; gap:.6rem; padding-top:.65rem; border-top:1px solid var(--ax-line-2); margin-top:.1rem;}
.aq-chip{display:inline-flex; align-items:center; font-size:.7rem; font-weight:700; padding:.22rem .55rem; border-radius:9999px; color:#fff; background:var(--c);}
.aq-card-f .val{font-weight:700; font-size:.85rem; color:var(--ax-ink);}
.aq-card-f .go{font-size:.78rem; font-weight:700; color:var(--ax-accent-strong); text-decoration:none;}
.aq-note{margin-top:1.4rem; font-size:.75rem; color:var(--ax-faint); max-width:80ch; line-height:1.55;}
.aq-back{display:inline-block; font-size:.8rem; font-weight:700; color:var(--ax-accent-strong); text-decoration:none; margin-bottom:.3rem;}
.aq-back:hover{text-decoration:underline;}
a.aq-row{color:inherit; text-decoration:none;}
a.aq-row:hover{border-color:var(--ax-accent-strong);}
.aq-card-f .go{cursor:pointer;}

/* AI action plan panel (Agent #1) */
.aq-plan{background:var(--ax-bg); border:1px solid var(--ax-line); border-radius:.9rem; box-shadow:var(--ax-shadow); padding:1.15rem 1.3rem; margin:0 0 1.2rem;}
.aq-plan-k{font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:var(--ax-accent-strong); display:flex; align-items:center; gap:.5rem; flex-wrap:wrap;}
.aq-plan-obj{font-size:1.05rem; font-weight:800; color:var(--ax-ink); margin:.4rem 0 .1rem; line-height:1.3;}
.aq-plan-sum{font-size:.86rem; color:var(--ax-muted); line-height:1.55; margin:.3rem 0 0; max-width:72ch;}
.aq-plan-h5{font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--ax-faint); margin:1rem 0 .4rem;}
.aq-steps{margin:0; padding-left:1.2rem; display:flex; flex-direction:column; gap:.3rem;}
.aq-steps li{font-size:.86rem; color:var(--ax-ink); line-height:1.45;}
.aq-focus{font-size:.86rem; color:var(--ax-ink); background:var(--ax-panel); border-radius:.5rem; padding:.55rem .7rem; line-height:1.45;}
.aq-watch{font-size:.8rem; color:var(--ax-muted); line-height:1.45; margin:0; padding-left:1.2rem; display:flex; flex-direction:column; gap:.25rem;}
.aq-plan-cta{display:flex; align-items:center; gap:.6rem; flex-wrap:wrap; margin-top:1.1rem; padding-top:.9rem; border-top:1px solid var(--ax-line-2);}
.aq-btn{font-size:.82rem; font-weight:700; padding:.5rem .95rem; border-radius:.55rem; border:1px solid transparent; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center;}
.aq-btn-primary{background:var(--ax-accent-strong); color:#fff;}
.aq-btn-ghost{background:transparent; border-color:var(--ax-line); color:var(--ax-muted);}
.aq-btn:disabled{opacity:.55; cursor:progress;}
.aq-conf{font-size:.68rem; font-weight:700; padding:.2rem .55rem; border-radius:9999px; background:var(--ax-panel); color:var(--ax-muted); text-transform:capitalize; letter-spacing:0;}
.aq-plan-meta{font-size:.72rem; color:var(--ax-faint); margin-left:auto; max-width:44ch; text-align:right;}
.aq-exec{background:var(--ax-panel); border-radius:.6rem; padding:.7rem .85rem; font-size:.84rem; color:var(--ax-ink); display:flex; align-items:center; gap:.55rem; flex-wrap:wrap; line-height:1.45;}
.aq-exec .ok{width:.6rem; height:.6rem; border-radius:50%; background:var(--ax-accent-strong); flex:0 0 auto;}
.aq-plan-empty{display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; margin-top:.55rem;}
.aq-plan-empty p{font-size:.85rem; color:var(--ax-muted); margin:0; max-width:58ch; line-height:1.5;}
</style>

@if(! ($q['ready'] ?? false))
    <div class="aq-hero"><div class="aq-hero-p">Select a tenant to see its action queue.</div></div>
@elseif(!empty($q['detail']))

<a href="{{ $q['back_url'] }}" class="aq-back">&larr; All campaigns</a>
<div class="aq-hero">
    <div class="aq-hero-kick">Campaign</div>
    <div class="aq-hero-h">{{ $q['detail']['name'] }}</div>
    <div class="aq-hero-p">{{ $q['detail']['count'] }} items &middot; {{ $q['detail']['value'] }} value at risk &middot; ranked by value. Open a row for its full investigation.</div>
</div>

@php($plan = $q['detail']['plan'] ?? null)
<div class="aq-plan">
    @if(! $plan)
        <div class="aq-plan-k">&#10022; AI action plan</div>
        <div class="aq-plan-empty">
            <p>Turn this campaign into a ready-to-work plan. Autnyx drafts the objective and the steps; you accept, and it creates the tasks and a PO draft for you.</p>
            <button class="aq-btn aq-btn-primary" wire:click="draftPlan" wire:loading.attr="disabled" wire:target="draftPlan">
                <span wire:loading.remove wire:target="draftPlan">Draft action plan</span>
                <span wire:loading wire:target="draftPlan">Drafting&hellip;</span>
            </button>
        </div>
    @elseif($plan['state'] === 'failed')
        <div class="aq-plan-k">&#10022; AI action plan</div>
        <p class="aq-plan-sum">The plan could not be generated just now. You can try again in a moment.</p>
        <div class="aq-plan-cta">
            <button class="aq-btn aq-btn-primary" wire:click="draftPlan" wire:loading.attr="disabled" wire:target="draftPlan">
                <span wire:loading.remove wire:target="draftPlan">Try again</span>
                <span wire:loading wire:target="draftPlan">Drafting&hellip;</span>
            </button>
        </div>
    @else
        <div class="aq-plan-k">&#10022; AI action plan
            @if(!empty($plan['confidence']))<span class="aq-conf">{{ $plan['confidence'] }} confidence</span>@endif
        </div>
        @if(!empty($plan['objective']))<div class="aq-plan-obj">{{ $plan['objective'] }}</div>@endif
        @if(!empty($plan['summary']))<div class="aq-plan-sum">{{ $plan['summary'] }}</div>@endif

        @if(!empty($plan['steps']))
            <div class="aq-plan-h5">Steps this week</div>
            <ol class="aq-steps">
                @foreach($plan['steps'] as $s)<li>{{ $s }}</li>@endforeach
            </ol>
        @endif

        @if(!empty($plan['priority_focus']))
            <div class="aq-plan-h5">Start here</div>
            <div class="aq-focus">{{ $plan['priority_focus'] }}</div>
        @endif

        @if(!empty($plan['expected_outcome']))
            <div class="aq-plan-h5">Expected outcome</div>
            <div class="aq-plan-sum" style="margin:0">{{ $plan['expected_outcome'] }}</div>
        @endif

        @if(!empty($plan['watchouts']))
            <div class="aq-plan-h5">Watch-outs</div>
            <ul class="aq-watch">@foreach($plan['watchouts'] as $w)<li>{{ $w }}</li>@endforeach</ul>
        @endif

        @if($plan['state'] === 'executed')
            <div class="aq-plan-h5">Executed</div>
            <div class="aq-exec">
                <span class="ok"></span>
                <span>{{ $plan['execution']['actions_created'] ?? 0 }} action(s) created &middot; {{ $plan['execution']['investigations_advanced'] ?? 0 }} investigation(s) moved into progress@if(!empty($plan['acted_by'])) &middot; accepted by {{ $plan['acted_by'] }}@endif @if(!empty($plan['executed_at'])) &middot; {{ $plan['executed_at'] }}@endif</span>
            </div>
            <div class="aq-plan-cta">
                @if($plan['po_available'])
                <button class="aq-btn aq-btn-primary" wire:click="downloadPoDraft({{ $plan['run_id'] }})">Download PO draft (CSV)</button>
                @endif
                <a class="aq-btn aq-btn-ghost" href="{{ $q['back_url'] }}">Back to campaigns</a>
                <span class="aq-plan-meta">Autnyx recommends; you accepted. Nothing was sent to any external system.</span>
            </div>
        @else
            <div class="aq-plan-cta">
                <button class="aq-btn aq-btn-primary" wire:click="acceptPlan({{ $plan['run_id'] }})" wire:loading.attr="disabled" wire:target="acceptPlan">
                    <span wire:loading.remove wire:target="acceptPlan">Accept &amp; execute</span>
                    <span wire:loading wire:target="acceptPlan">Working&hellip;</span>
                </button>
                <button class="aq-btn aq-btn-ghost" wire:click="dismissPlan({{ $plan['run_id'] }})">Dismiss</button>
                <button class="aq-btn aq-btn-ghost" wire:click="draftPlan" wire:loading.attr="disabled" wire:target="draftPlan">Re-draft</button>
                <span class="aq-plan-meta">On accept, Autnyx creates the tasks &amp; advances the investigations. It never writes to your ERP.</span>
            </div>
        @endif
    @endif
</div>

<div class="aq-queue" style="margin-top:1rem">
    @foreach($q['detail']['rows'] as $r)
    <a class="aq-row" @if($r['url']) href="{{ $r['url'] }}" @endif>
        <span class="aq-pip {{ $r['sev'] }}"></span>
        <div><div class="t">{{ $r['sku'] }}</div><div class="s">{{ $r['store'] }}</div></div>
        <div class="v">{{ $r['val_fmt'] }}</div>
        <span class="b">Open &rarr;</span>
    </a>
    @endforeach
</div>

@else

<div class="aq-hero">
    <div class="aq-hero-kick">The exception queue, made workable</div>
    <div class="aq-hero-h">{{ $q['total_open'] }} open investigations, grouped into {{ $q['campaign_count'] }} campaigns.</div>
    <div class="aq-hero-p">Trend signals (demand erosion, seasonal shifts) are reviewed in bulk, not one-by-one — they roll into campaigns ranked by value at risk. Work the short act-now list first, then the campaigns.</div>
    <div class="aq-flow">
        <div class="aq-step"><div class="n">{{ $q['total_open'] }}</div><div class="l">open investigations</div></div>
        <div class="aq-arrow">&rarr;</div>
        <div class="aq-step"><div class="n">{{ $q['act_count'] }}</div><div class="l">urgent actions this week</div></div>
        <div class="aq-arrow">+</div>
        <div class="aq-step"><div class="n">{{ $q['campaign_count'] }}</div><div class="l">campaigns to review</div></div>
        <div class="aq-arrow">&middot;</div>
        <div class="aq-step"><div class="n" style="font-size:1.25rem">{{ $q['total_value'] }}</div><div class="l">total value at risk</div></div>
    </div>
</div>

@if(!empty($q['act_now']))
<div class="aq-sec"><h3>Act this week</h3><span class="m">{{ $q['act_count'] }} individual exceptions · ranked by value</span></div>
<div class="aq-queue">
    @foreach($q['act_now'] as $r)
    <div class="aq-row">
        <span class="aq-pip {{ $r['sev'] }}"></span>
        <div><div class="t">{{ $r['title'] }}</div><div class="s">{{ $r['sub'] }}</div></div>
        <div class="v">{{ $r['val_fmt'] }}</div>
        <span class="b">{{ $r['verb'] }}</span>
    </div>
    @endforeach
</div>
@endif

<div class="aq-sec"><h3>Campaigns</h3><span class="m">same-shaped signals rolled together</span></div>
<div class="aq-grid">
    @foreach($q['campaigns'] as $c)
    <div class="aq-card aq-a-{{ $c['accent'] }}">
        <div class="aq-card-h">
            <div>
                <h4>{{ $c['name'] }}</h4>
                <div class="act">{{ $c['action'] }}</div>
            </div>
            <div class="cnt">{{ $c['skus'] }}<small>{{ $c['kind'] === 'trend' ? 'SKUs' : 'items' }}</small></div>
        </div>
        @foreach($c['examples'] as $e)
        <div class="aq-ex"><span><span class="sk">{{ $e['sku'] }}</span> <span class="lo">{{ $e['store'] }}</span></span><span class="ev">{{ $e['val_fmt'] }}</span></div>
        @endforeach
        <div class="aq-card-f">
            <span class="aq-chip">{{ $c['kind'] === 'trend' ? 'Bulk review' : ($c['high'] > 0 ? $c['high'].' high' : 'Review') }}</span>
            <span class="val">{{ $c['value_fmt'] }}</span>
            <a class="go" href="{{ \App\Filament\Pages\ActionQueue::getUrl(['campaign' => $c['name']]) }}">Open &rarr;</a>
        </div>
    </div>
    @endforeach
</div>

<div class="aq-note">
    All counts and values are deterministic aggregates over this tenant&rsquo;s active anomalies — nothing is generated on this page. Trend campaigns (demand erosion, seasonal shift) are gated at a materiality floor, so low-value tail SKUs are summarised in the campaign rather than raised as individual cases. &ldquo;Open&rdquo; links through to the full investigation list.
</div>

@endif

</x-filament-panels::page>
