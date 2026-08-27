<x-filament-panels::page>
    @php [$availFrom, $availTo] = $this->availableRange(); @endphp

    <style>
        .rp-controls { background:var(--ax-bg); border:1px solid var(--ax-line); border-radius:.875rem; box-shadow:var(--ax-shadow); padding:1.15rem 1.35rem; margin-bottom:1.25rem; }
        .rp-controls-title { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--ax-faint); margin:0 0 .65rem; }
        .rp-row { display:flex; flex-wrap:wrap; align-items:flex-end; gap:.9rem; }
        .rp-field { display:flex; flex-direction:column; gap:.25rem; }
        .rp-field label { font-size:.72rem; font-weight:600; color:var(--ax-muted); }
        .rp-field input { border:1px solid var(--ax-line); border-radius:.45rem; padding:.4rem .55rem; font-size:.82rem; background:var(--ax-bg); color:var(--ax-ink); }
        .rp-presets { display:flex; flex-wrap:wrap; gap:.4rem; margin-left:auto; }
        .rp-preset { font-size:.72rem; border:1px solid var(--ax-line); background:var(--ax-panel); color:var(--ax-text); border-radius:9999px; padding:.3rem .7rem; cursor:pointer; }
        .rp-preset:hover { border-color:var(--ax-accent-300); background:var(--ax-accent-soft); color:var(--ax-accent-strong); }
        .rp-hint { margin-top:.6rem; font-size:.72rem; color:var(--ax-faint); }

        .rp-grid { display:grid; grid-template-columns:1fr; gap:1rem; }
        @media(min-width:768px){ .rp-grid { grid-template-columns:1fr 1fr; } }
        .rp-card { background:var(--ax-bg); border:1px solid var(--ax-line); border-radius:.875rem; box-shadow:var(--ax-shadow); padding:1.2rem 1.35rem; display:flex; flex-direction:column; }
        .rp-card-title { font-size:1rem; font-weight:700; color:var(--ax-ink); display:flex; align-items:center; gap:.5rem; }
        .rp-dot { width:.55rem; height:.55rem; border-radius:9999px; flex-shrink:0; }
        .rp-card-desc { font-size:.82rem; color:var(--ax-muted); line-height:1.55; margin:.5rem 0 1rem; flex:1; }
        .rp-actions { display:flex; gap:.6rem; }
        .rp-btn { display:inline-flex; align-items:center; gap:.4rem; font-size:.8rem; font-weight:600; text-decoration:none; border-radius:.5rem; padding:.5rem .85rem; border:1px solid transparent; }
        .rp-btn-pdf { background:var(--ax-accent-strong); color:var(--ax-accent-fg); }
        .rp-btn-pdf:hover { background:var(--ax-accent-800); }
        .rp-btn-xlsx { background:var(--ax-success-soft); color:var(--ax-success-fg); border-color:var(--ax-success-soft); }
        .rp-btn-xlsx:hover { filter:brightness(.97); }
    </style>

    {{-- Date range controls --}}
    <div class="rp-controls">
        <p class="rp-controls-title">Reporting period</p>
        <div class="rp-row">
            <div class="rp-field">
                <label>From</label>
                <input type="date" wire:model.live="from" min="{{ $availFrom }}" max="{{ $availTo }}">
            </div>
            <div class="rp-field">
                <label>To</label>
                <input type="date" wire:model.live="to" min="{{ $availFrom }}" max="{{ $availTo }}">
            </div>
            <div class="rp-presets">
                <button type="button" class="rp-preset" wire:click="setPreset('7d')">7 days</button>
                <button type="button" class="rp-preset" wire:click="setPreset('30d')">30 days</button>
                <button type="button" class="rp-preset" wire:click="setPreset('90d')">90 days</button>
                <button type="button" class="rp-preset" wire:click="setPreset('mtd')">Month</button>
                <button type="button" class="rp-preset" wire:click="setPreset('ytd')">YTD</button>
                <button type="button" class="rp-preset" wire:click="setPreset('all')">All time</button>
            </div>
        </div>
        <p class="rp-hint">Data available from <strong>{{ \Illuminate\Support\Carbon::parse($availFrom)->format('M j, Y') }}</strong> to <strong>{{ \Illuminate\Support\Carbon::parse($availTo)->format('M j, Y') }}</strong>. Ranges are clamped to this window.</p>
    </div>

    {{-- Report cards --}}
    <div class="rp-grid">
        @foreach($this->getReports() as $report)
        <div class="rp-card">
            <div class="rp-card-title">
                <span class="rp-dot" style="background:{{ $report['accent'] }}"></span>
                {{ $report['label'] }}
            </div>
            <p class="rp-card-desc">{{ $report['description'] }}</p>
            <div class="rp-actions">
                <a class="rp-btn rp-btn-pdf" href="{{ $this->downloadUrl($report['type'], 'pdf') }}" target="_blank" rel="noopener">
                    <svg style="width:1rem;height:1rem" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3M4 6a2 2 0 012-2h8l6 6v8a2 2 0 01-2 2H6a2 2 0 01-2-2V6z"/></svg>
                    PDF
                </a>
                <a class="rp-btn rp-btn-xlsx" href="{{ $this->downloadUrl($report['type'], 'xlsx') }}" target="_blank" rel="noopener">
                    <svg style="width:1rem;height:1rem" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-6h6v6M5 4h14a1 1 0 011 1v14a1 1 0 01-1 1H5a1 1 0 01-1-1V5a1 1 0 011-1z"/></svg>
                    Excel
                </a>
            </div>
        </div>
        @endforeach
    </div>
</x-filament-panels::page>
