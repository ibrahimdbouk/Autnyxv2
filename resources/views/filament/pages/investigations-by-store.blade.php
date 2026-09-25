<x-filament-panels::page>

@php($groups = $this->getStoreGroups())

<style>
.ibs-sub{font-size:.85rem; color:var(--ax-faint); margin:-.4rem 0 1.1rem; max-width:80ch; line-height:1.5;}
.ibs-panel{border:1px solid var(--ax-line); border-radius:.85rem; background:var(--ax-bg); margin-bottom:.7rem; overflow:hidden; box-shadow:var(--ax-shadow);}
.ibs-panel > summary{list-style:none; cursor:pointer; display:flex; align-items:center; gap:.8rem; padding:.85rem 1.15rem; user-select:none;}
.ibs-panel > summary::-webkit-details-marker{display:none;}
.ibs-chev{transition:transform .15s; color:var(--ax-faint); font-size:.8rem;}
.ibs-panel[open] .ibs-chev{transform:rotate(90deg);}
.ibs-name{font-weight:800; font-size:.95rem; color:var(--ax-ink);}
.ibs-code{font-size:.74rem; color:var(--ax-faint); font-weight:600;}
.ibs-badges{margin-left:auto; display:flex; gap:.4rem; flex-wrap:wrap; align-items:center;}
.ibs-badge{font-size:.72rem; font-weight:700; padding:.22rem .55rem; border-radius:9999px; background:var(--ax-panel); color:var(--ax-muted);}
.ibs-badge.open{background:var(--ax-warning-soft); color:var(--ax-warning-fg);}
.ibs-badge.urgent{background:var(--ax-danger-soft); color:var(--ax-danger-fg);}
.ibs-badge.value{background:var(--ax-accent-soft); color:var(--ax-accent-strong);}
.ibs-body{border-top:1px solid var(--ax-line); padding:.4rem 0;}
.ibs-tbl{width:100%; border-collapse:collapse; font-size:.83rem;}
.ibs-tbl th{text-align:left; font-size:.68rem; text-transform:uppercase; letter-spacing:.05em; color:var(--ax-faint); font-weight:700; padding:.5rem 1.15rem;}
.ibs-tbl td{padding:.5rem 1.15rem; border-top:1px solid var(--ax-line); color:var(--ax-ink); vertical-align:middle;}
.ibs-tbl tr:first-child td{border-top:0;}
.ibs-tbl a{color:var(--ax-accent-strong); font-weight:700; text-decoration:none;}
.ibs-pill{font-size:.68rem; font-weight:700; padding:.15rem .5rem; border-radius:9999px; text-transform:capitalize;}
.ibs-pill.s-open{background:var(--ax-warning-soft); color:var(--ax-warning-fg);}
.ibs-pill.s-in_progress{background:var(--ax-info-soft); color:var(--ax-info-fg);}
.ibs-pill.s-resolved{background:var(--ax-success-soft); color:var(--ax-success-fg);}
.ibs-pill.s-closed{background:var(--ax-panel); color:var(--ax-muted);}
.ibs-pri{font-size:.72rem; font-weight:700;}
.ibs-pri.critical{color:var(--ax-danger-fg);}
.ibs-pri.high{color:var(--ax-warning-fg);}
.ibs-pri.medium{color:var(--ax-info-fg);}
.ibs-pri.low{color:var(--ax-faint);}
.ibs-more{font-size:.75rem; color:var(--ax-faint); padding:.5rem 1.15rem;}
.ibs-empty{background:var(--ax-bg); border:1px dashed var(--ax-line); border-radius:1rem; padding:2rem; text-align:center; color:var(--ax-muted);}
</style>

<div class="ibs-sub">Every store with its investigation load — total, still open, urgent (critical/high open), and value at risk — ordered by where the open work is. Open a store to see and jump into its investigations.</div>

@if(empty($groups))
    <div class="ibs-empty"><p>No investigations to group yet. Once signals are investigated, each store's load appears here.</p></div>
@else
    @foreach($groups as $g)
        <details class="ibs-panel" @if($loop->first && ($g['open'] ?? 0) > 0) open @endif>
            <summary>
                <span class="ibs-chev">▶</span>
                <span>
                    <span class="ibs-name">{{ $g['store'] }}</span>
                    @if(!empty($g['code']))<span class="ibs-code"> · {{ $g['code'] }}</span>@endif
                    @if(!empty($g['city']))<span class="ibs-code"> · {{ $g['city'] }}</span>@endif
                </span>
                <span class="ibs-badges">
                    <span class="ibs-badge">{{ $g['total'] }} total</span>
                    @if($g['open'] > 0)<span class="ibs-badge open">{{ $g['open'] }} open</span>@endif
                    @if($g['urgent'] > 0)<span class="ibs-badge urgent">{{ $g['urgent'] }} urgent</span>@endif
                    <span class="ibs-badge value">{{ $g['value_fmt'] }} at risk</span>
                </span>
            </summary>
            <div class="ibs-body">
                <table class="ibs-tbl">
                    <thead>
                        <tr><th>Investigation</th><th>Status</th><th>Priority</th><th>Value at risk</th><th>Opened</th><th></th></tr>
                    </thead>
                    <tbody>
                        @foreach($g['investigations'] as $inv)
                            <tr>
                                <td>{{ $inv['title'] }}</td>
                                <td><span class="ibs-pill s-{{ $inv['status'] }}">{{ $inv['status_label'] }}</span></td>
                                <td><span class="ibs-pri {{ $inv['priority'] }}">{{ $inv['priority_label'] }}</span></td>
                                <td>{{ $inv['value_fmt'] }}</td>
                                <td>{{ $inv['opened'] ?? '—' }}</td>
                                <td><a href="{{ $inv['url'] }}">Open →</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if($g['total'] > $g['shown'])
                    <div class="ibs-more">Showing the most recent {{ $g['shown'] }} of {{ $g['total'] }} — open the Investigations list for the full history.</div>
                @endif
            </div>
        </details>
    @endforeach
@endif

</x-filament-panels::page>
