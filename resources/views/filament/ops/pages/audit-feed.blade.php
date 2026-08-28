<x-filament-panels::page>
    @php
        $rows = $this->getRows();
        $eventColor = fn ($e) => match (true) {
            str_contains($e, 'failed')        => '#dc2626',
            str_starts_with($e, 'impersonat') => '#b45309',
            str_starts_with($e, 'sso')        => '#2563eb',
            $e === 'data_exported'            => '#7c3aed',
            default                           => '#6b7280',
        };
    @endphp

    <style>
        .af-chips { display:flex; flex-wrap:wrap; gap:.4rem; margin-bottom:1rem; }
        .af-chip { padding:.3rem .7rem; border-radius:9999px; font-size:.78rem; font-weight:600; border:1px solid var(--ax-line,#e5e7eb); background:var(--ax-bg,#fff); color:var(--ax-text,#374151); cursor:pointer; }
        .af-chip.on { background:#b45309; color:#fff; border-color:#b45309; }
        .af-card { background:var(--ax-bg,#fff); border:1px solid var(--ax-line,#e5e7eb); border-radius:.9rem; box-shadow:var(--ax-shadow,0 1px 2px rgba(0,0,0,.04)); overflow:hidden; }
        table.af-tbl { width:100%; border-collapse:collapse; font-size:.82rem; }
        table.af-tbl th { text-align:left; color:var(--ax-muted,#6b7280); font-size:.68rem; text-transform:uppercase; padding:.55rem .9rem; border-bottom:1px solid var(--ax-line,#e5e7eb); white-space:nowrap; }
        table.af-tbl td { padding:.55rem .9rem; border-bottom:1px solid var(--ax-line-2,#f3f4f6); color:var(--ax-text,#374151); vertical-align:top; }
        .af-evt { display:inline-block; padding:.1rem .5rem; border-radius:6px; font-size:.68rem; font-weight:700; color:#fff; white-space:nowrap; }
        .af-muted { color:var(--ax-muted,#6b7280); }
        .af-scroll { overflow-x:auto; }
    </style>

    <div class="af-chips">
        @foreach($this->getEventOptions() as $val => $label)
            <span class="af-chip {{ ($this->event ?? '') === $val ? 'on' : '' }}" wire:click="setEvent('{{ $val }}')">{{ $label }}</span>
        @endforeach
    </div>

    <div class="af-card">
        <div class="af-scroll">
            <table class="af-tbl">
                <thead><tr><th>When</th><th>Event</th><th>Tenant</th><th>Actor</th><th>Detail</th></tr></thead>
                <tbody>
                    @forelse($rows as $r)
                        <tr>
                            <td class="af-muted" title="{{ $r->created_at }}">{{ \Illuminate\Support\Carbon::parse($r->created_at)->diffForHumans() }}</td>
                            <td><span class="af-evt" style="background:{{ $eventColor($r->event_type) }}">{{ $r->event_type }}</span></td>
                            <td>{{ $r->tenant_name ?? '—' }}</td>
                            <td class="af-muted">{{ $r->user_email ?? 'system' }}</td>
                            <td>{{ \Illuminate\Support\Str::limit($r->description, 90) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="af-muted" style="text-align:center;padding:2rem;">No audit events for this filter.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
