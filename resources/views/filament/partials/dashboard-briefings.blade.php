{{-- Dashboard briefings row: Daily (left) · Weekly (right). Self-contained; reads
     the latest governed AgentRun for the current tenant. Narration only. --}}
@php
    $dbrfTenant = \Filament\Facades\Filament::getTenant()?->id;
    $dbrfLatest = function (string $key) use ($dbrfTenant) {
        if (! $dbrfTenant) {
            return null;
        }
        return \App\Models\AgentRun::where('tenant_id', $dbrfTenant)
            ->where('agent_key', $key)
            ->whereIn('status', [\App\Models\AgentRun::STATUS_COMPLETE, \App\Models\AgentRun::STATUS_FAILED])
            ->latest('id')
            ->first();
    };
    $dbrfDaily  = $dbrfLatest(\App\Models\AgentRun::KEY_DAILY_BRIEFING);
    $dbrfWeekly = $dbrfLatest(\App\Models\AgentRun::KEY_WEEKLY_BRIEFING);
    try { $dbrfDailyUrl = \App\Filament\Pages\DailyBriefing::getUrl(); } catch (\Throwable $e) { $dbrfDailyUrl = '#'; }
    try { $dbrfWeeklyUrl = \App\Filament\Pages\WeeklyBriefing::getUrl(); } catch (\Throwable $e) { $dbrfWeeklyUrl = '#'; }
@endphp

<style>
.dbrf-row{display:grid; grid-template-columns:1fr 1fr; gap:.9rem; margin-bottom:1.1rem;}
@media(max-width:820px){ .dbrf-row{grid-template-columns:1fr;} }
.dbrf-card{background:var(--ax-bg); border:1px solid var(--ax-line); border-radius:.9rem; box-shadow:var(--ax-shadow); padding:1.05rem 1.2rem; display:flex; flex-direction:column;}
.dbrf-kick{font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:var(--ax-accent-strong); display:flex; align-items:center; gap:.5rem; flex-wrap:wrap;}
.dbrf-kick .ago{margin-left:auto; color:var(--ax-faint); font-weight:600; letter-spacing:0; text-transform:none;}
.dbrf-h{font-size:1.02rem; font-weight:800; color:var(--ax-ink); margin:.45rem 0 0; line-height:1.35;}
.dbrf-sum{font-size:.85rem; color:var(--ax-muted); margin:.4rem 0 0; line-height:1.55;}
.dbrf-foot{margin-top:.7rem; display:flex; align-items:center; gap:.6rem;}
.dbrf-link{font-size:.8rem; font-weight:700; color:var(--ax-accent-strong); text-decoration:none;}
.dbrf-conf{font-size:.64rem; font-weight:700; padding:.15rem .5rem; border-radius:9999px; background:var(--ax-panel); color:var(--ax-muted); text-transform:capitalize;}
.dbrf-empty{font-size:.85rem; color:var(--ax-muted); margin:.4rem 0 0; line-height:1.5;}
</style>

<div class="dbrf-row">
    {{-- Daily (left) --}}
    <div class="dbrf-card">
        <div class="dbrf-kick">☀ Daily briefing
            @if($dbrfDaily && ! $dbrfDaily->isFailed() && $dbrfDaily->confidence)<span class="dbrf-conf">{{ $dbrfDaily->confidence }}</span>@endif
            @if($dbrfDaily)<span class="ago">{{ optional($dbrfDaily->created_at)->diffForHumans() }}</span>@endif
        </div>
        @if($dbrfDaily && ! $dbrfDaily->isFailed() && $dbrfDaily->out('headline'))
            <div class="dbrf-h">{{ $dbrfDaily->out('headline') }}</div>
            @if($dbrfDaily->out('summary'))<div class="dbrf-sum">{{ \Illuminate\Support\Str::limit($dbrfDaily->out('summary'), 220) }}</div>@endif
        @else
            <div class="dbrf-empty">No daily briefing yet today. Generate the start-of-day note from what landed overnight and what needs a decision.</div>
        @endif
        <div class="dbrf-foot">
            <a class="dbrf-link" href="{{ $dbrfDailyUrl }}">Open Daily Briefing →</a>
        </div>
    </div>

    {{-- Weekly (right) --}}
    <div class="dbrf-card">
        <div class="dbrf-kick">🗞 Weekly briefing
            @if($dbrfWeekly && ! $dbrfWeekly->isFailed() && $dbrfWeekly->confidence)<span class="dbrf-conf">{{ $dbrfWeekly->confidence }}</span>@endif
            @if($dbrfWeekly)<span class="ago">{{ optional($dbrfWeekly->created_at)->diffForHumans() }}</span>@endif
        </div>
        @if($dbrfWeekly && ! $dbrfWeekly->isFailed() && $dbrfWeekly->out('headline'))
            <div class="dbrf-h">{{ $dbrfWeekly->out('headline') }}</div>
            @if($dbrfWeekly->out('summary'))<div class="dbrf-sum">{{ \Illuminate\Support\Str::limit($dbrfWeekly->out('summary'), 220) }}</div>@endif
        @else
            <div class="dbrf-empty">No weekly briefing yet. Generate the Monday state-of-the-business note across the last seven days.</div>
        @endif
        <div class="dbrf-foot">
            <a class="dbrf-link" href="{{ $dbrfWeeklyUrl }}">Open Weekly Briefing →</a>
        </div>
    </div>
</div>
