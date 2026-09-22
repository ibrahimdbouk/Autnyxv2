{{-- Dashboard data-feed health strip: per-dataset READY / AMBER / BLOCKED from the
     firewall's latest batch decision (DataReadinessService). One cheap query. --}}
@php
    $dfhTenant = \Filament\Facades\Filament::getTenant()?->id;
    $dfhStates = [];
    if ($dfhTenant) {
        try {
            $dfhStates = app(\App\Services\DataQuality\DataReadinessService::class)->datasetStates($dfhTenant);
        } catch (\Throwable $e) {
            $dfhStates = [];
        }
    }
    $dfhBlocked = 0;
    $dfhAmber = 0;
    foreach ($dfhStates as $dfhState) {
        if ($dfhState === 'red') { $dfhBlocked++; }
        elseif ($dfhState === 'amber') { $dfhAmber++; }
    }
    try { $dfhReadinessUrl = \App\Filament\Pages\DataReadiness::getUrl(); } catch (\Throwable $e) { $dfhReadinessUrl = '#'; }
@endphp

@if(! empty($dfhStates))
<style>
.dfh-wrap{background:var(--ax-bg); border:1px solid var(--ax-line); border-radius:.9rem; box-shadow:var(--ax-shadow); padding:.85rem 1.1rem; margin-bottom:1.1rem; display:flex; align-items:center; gap:.9rem; flex-wrap:wrap;}
.dfh-title{font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--ax-faint);}
.dfh-chips{display:flex; gap:.45rem; flex-wrap:wrap; flex:1;}
.dfh-chip{font-size:.75rem; font-weight:700; padding:.28rem .6rem; border-radius:9999px; display:inline-flex; align-items:center; gap:.35rem; border:1px solid transparent;}
.dfh-chip.ok{background:#e7f6ee; color:#15803d; border-color:#bfe6cf;}
.dfh-chip.amber{background:#fdf3e0; color:#8a5a00; border-color:#f6d998;}
.dfh-chip.red{background:#fdeceb; color:#b91c1c; border-color:#f6c9c4;}
.dfh-dot{width:.5rem; height:.5rem; border-radius:9999px; background:currentColor;}
.dfh-link{font-size:.78rem; font-weight:700; color:var(--ax-accent-strong); text-decoration:none; margin-left:auto; white-space:nowrap;}
</style>
<div class="dfh-wrap">
    <span class="dfh-title">Data feeds
        @if($dfhBlocked > 0)· {{ $dfhBlocked }} blocked @elseif($dfhAmber > 0)· {{ $dfhAmber }} with exceptions @else· all healthy @endif
    </span>
    <div class="dfh-chips">
        @foreach($dfhStates as $dfhType => $dfhState)
            <span class="dfh-chip {{ $dfhState === 'red' ? 'red' : ($dfhState === 'amber' ? 'amber' : 'ok') }}">
                <span class="dfh-dot"></span>{{ ucwords(str_replace('_', ' ', $dfhType)) }}
            </span>
        @endforeach
    </div>
    <a class="dfh-link" href="{{ $dfhReadinessUrl }}">Data Readiness →</a>
</div>
@endif
