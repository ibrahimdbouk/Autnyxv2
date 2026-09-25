<x-filament-panels::page>
    @php($p = $this->progress())
    @php($steps = $this->steps())
    @php($imports = $this->importsUrl())
    <style>
        .gs-wrap { display:flex; flex-direction:column; gap:1rem; max-width:60rem; }
        .gs-head { border:1px solid var(--ax-line); border-radius:1rem; padding:1.1rem 1.3rem; background:var(--ax-bg); }
        .gs-bar { height:.5rem; border-radius:999px; background:var(--ax-line); overflow:hidden; margin-top:.6rem; }
        .gs-bar > span { display:block; height:100%; background:var(--ax-success-fg, #16a34a); }
        .gs-step { display:flex; gap:.9rem; border:1px solid var(--ax-line); border-radius:.85rem; padding:.9rem 1.1rem; background:var(--ax-bg); align-items:flex-start; }
        .gs-dot { flex:none; width:1.6rem; height:1.6rem; border-radius:999px; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:.8rem; border:2px solid var(--ax-line); color:var(--ax-muted); }
        .gs-dot.done { background:var(--ax-success-soft); border-color:var(--ax-success-fg); color:var(--ax-success-fg); }
        .gs-title { font-weight:700; color:var(--ax-ink); }
        .gs-level { font-size:.66rem; font-weight:800; letter-spacing:.05em; padding:.1rem .4rem; border-radius:.3rem; margin-left:.4rem; vertical-align:middle; }
        .gs-level.required { color:var(--ax-danger-fg); background:var(--ax-danger-soft); }
        .gs-level.recommended { color:var(--ax-warning-fg); background:var(--ax-warning-soft); }
        .gs-level.optional { color:var(--ax-neutral-fg); background:var(--ax-neutral-soft); }
        .gs-muted { color:var(--ax-muted); font-size:.85rem; line-height:1.45; }
        .gs-links { display:flex; gap:.9rem; flex-wrap:wrap; margin-top:.45rem; font-size:.82rem; }
        .gs-links a { color:var(--ax-primary, #4f46e5); font-weight:600; }
    </style>

    <div class="gs-wrap">
        <div class="gs-head">
            <div style="font-weight:800;font-size:1.1rem;color:var(--ax-ink)">
                {{ $p['required_left'] === 0 ? 'Set up — detection runs every night on its own.' : $p['required_left'] . ' required step(s) left' }}
            </div>
            <div class="gs-muted">{{ $p['done'] }} of {{ $p['total'] }} steps done. Most teams finish in a morning: four files, one detection run, one investigation.</div>
            <div class="gs-bar"><span style="width: {{ $p['pct'] }}%"></span></div>
        </div>

        @foreach($steps as $i => $s)
            <div class="gs-step" id="step-{{ $s['key'] }}">
                <div class="gs-dot {{ $s['done'] ? 'done' : '' }}">{{ $s['done'] ? '✓' : $i + 1 }}</div>
                <div style="flex:1;min-width:0">
                    <div class="gs-title">{{ $s['title'] }} <span class="gs-level {{ $s['level'] }}">{{ strtoupper($s['level']) }}</span></div>
                    <div class="gs-muted">{{ $s['why'] }}</div>
                    <div class="gs-muted" style="margin-top:.25rem"><strong>{{ $s['done'] ? 'Done' : 'Status' }}:</strong> {{ $s['detail'] }}</div>
                    <div class="gs-links">
                        @if($s['type'])
                            <a href="{{ route('import-template', ['type' => $s['type']]) }}">Download template</a>
                            @if($imports)<a href="{{ $imports }}">Upload the file</a>@endif
                        @elseif($s['key'] === 'detection')
                            {{ $this->runDetectionAction }}
                        @elseif($s['key'] === 'investigate' && \App\Filament\Pages\ActionQueue::canAccess())
                            <a href="{{ \App\Filament\Pages\ActionQueue::getUrl() }}">Open the action queue</a>
                        @elseif($s['key'] === 'team' && \App\Filament\Resources\UserResource::canViewAny())
                            <a href="{{ \App\Filament\Resources\UserResource::getUrl('index') }}">Invite users</a>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>
    <x-filament-actions::modals />
</x-filament-panels::page>
