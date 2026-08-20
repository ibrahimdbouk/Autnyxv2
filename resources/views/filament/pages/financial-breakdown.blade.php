<x-filament-panels::page>

@php
    $fb = $this->getBreakdown();
@endphp

<style>
.fb-tabs { display:flex; flex-wrap:wrap; gap:.5rem; margin-bottom:1.25rem; }
.fb-tab {
    padding:.4rem .85rem; border-radius:9999px; font-size:.775rem; font-weight:600;
    border:1px solid #e5e7eb; background:#fff; color:#374151; cursor:pointer;
    text-decoration:none; white-space:nowrap; transition:all .12s ease;
}
.fb-tab:hover { border-color:#c4b5fd; background:#faf5ff; color:#6d28d9; }
.fb-tab-active { background:#6d28d9; border-color:#6d28d9; color:#fff; }
.fb-tab-active:hover { background:#5b21b6; color:#fff; }

.fb-hero {
    background:#fff; border:1px solid #e5e7eb; border-radius:1rem;
    box-shadow:0 1px 4px rgba(0,0,0,.06); padding:1.5rem 1.75rem; margin-bottom:1.25rem;
}
.fb-hero-label { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#9ca3af; }
.fb-hero-value { font-size:2.5rem; font-weight:800; color:#111827; line-height:1.1; margin:.35rem 0 .6rem; }
.fb-hero-formula { font-size:.85rem; color:#6b7280; line-height:1.6; max-width:70ch; }

.fb-components { display:grid; grid-template-columns:1fr; gap:.75rem; margin-bottom:1.25rem; }
@media(min-width:640px){ .fb-components { grid-template-columns:repeat(3,1fr); } }
.fb-comp {
    background:#f9fafb; border:1px solid #e5e7eb; border-radius:.75rem; padding:.9rem 1.1rem;
}
.fb-comp-label { font-size:.7rem; font-weight:600; text-transform:uppercase; letter-spacing:.04em; color:#9ca3af; }
.fb-comp-value { font-size:1.35rem; font-weight:700; color:#111827; margin-top:.2rem; }

.fb-card { background:#fff; border:1px solid #e5e7eb; border-radius:1rem; box-shadow:0 1px 4px rgba(0,0,0,.06); overflow:hidden; }
.fb-card-head {
    padding:.75rem 1.25rem; border-bottom:1px solid #e5e7eb; background:#f9fafb;
    font-size:.775rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#374151;
}
.fb-table { width:100%; border-collapse:collapse; font-size:.825rem; }
.fb-table th {
    padding:.55rem 1.25rem; font-size:.68rem; font-weight:700; text-transform:uppercase;
    letter-spacing:.05em; color:#9ca3af; border-bottom:1px solid #e5e7eb; background:#f9fafb; text-align:left;
}
.fb-table th:last-child, .fb-table td:last-child { text-align:right; }
.fb-table td { padding:.65rem 1.25rem; border-bottom:1px solid #f3f4f6; color:#374151; vertical-align:middle; }
.fb-table tr:last-child td { border-bottom:none; }
.fb-table tr.fb-row-link { cursor:pointer; }
.fb-table tr.fb-row-link:hover td { background:#faf5ff; }
.fb-inv-id { font-family:monospace; font-size:.75rem; color:#6d28d9; font-weight:700; text-decoration:none; }
.fb-inv-title { font-weight:600; color:#111827; }
.fb-amount { font-weight:700; color:#111827; white-space:nowrap; }
.fb-empty { padding:2rem 1.25rem; text-align:center; font-size:.85rem; color:#9ca3af; }
.fb-note {
    margin-top:1rem; font-size:.75rem; color:#9ca3af; display:flex; align-items:center; gap:.4rem;
}

.dark .fb-hero, .dark .fb-card { background:#1f2937; border-color:#374151; }
.dark .fb-hero-value, .dark .fb-comp-value, .dark .fb-inv-title, .dark .fb-amount { color:#f9fafb; }
.dark .fb-comp { background:#111827; border-color:#374151; }
.dark .fb-card-head, .dark .fb-table th { background:#111827; border-color:#374151; color:#9ca3af; }
.dark .fb-table td { color:#d1d5db; border-color:#374151; }
.dark .fb-table tr.fb-row-link:hover td { background:#2e1065; }
.dark .fb-tab { background:#1f2937; border-color:#374151; color:#d1d5db; }
.dark .fb-tab:hover { background:#2e1065; }
.dark .fb-inv-id { color:#a78bfa; }
</style>

{{-- Metric switcher --}}
<div class="fb-tabs">
    @foreach($this->getTabs() as $tab)
        <a
            href="{{ \App\Filament\Pages\FinancialBreakdown::getUrl(['metric' => $tab['metric']]) }}"
            class="fb-tab {{ $this->metric === $tab['metric'] ? 'fb-tab-active' : '' }}"
        >{{ $tab['label'] }}</a>
    @endforeach
</div>

{{-- Headline + formula --}}
<div class="fb-hero">
    <div class="fb-hero-label">{{ $fb['label'] }}</div>
    <div class="fb-hero-value">{{ $fb['value'] }}</div>
    <div class="fb-hero-formula">{{ $fb['formula'] }}</div>
</div>

{{-- Component figures --}}
@if(!empty($fb['components']))
<div class="fb-components">
    @foreach($fb['components'] as $comp)
    <div class="fb-comp">
        <div class="fb-comp-label">{{ $comp['label'] }}</div>
        <div class="fb-comp-value">{{ $comp['value'] }}</div>
    </div>
    @endforeach
</div>
@endif

{{-- Contributing rows --}}
<div class="fb-card">
    <div class="fb-card-head">Contributing Investigations &amp; Outcomes</div>
    @if(!empty($fb['rows']))
    <div style="overflow-x:auto">
        <table class="fb-table">
            <thead>
                <tr>
                    @foreach($fb['rowsHeader'] as $h)
                        <th>{{ $h }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($fb['rows'] as $row)
                <tr class="{{ $row['url'] ? 'fb-row-link' : '' }}"
                    @if($row['url']) onclick="window.location.href='{{ $row['url'] }}'" @endif>
                    <td>
                        @if($row['url'])
                            <a href="{{ $row['url'] }}" class="fb-inv-id" onclick="event.stopPropagation()">#{{ $row['id'] }}</a>
                        @else
                            <span class="fb-inv-id">#{{ $row['id'] }}</span>
                        @endif
                        <div class="fb-inv-title">{{ \Illuminate\Support\Str::limit($row['title'], 60) }}</div>
                    </td>
                    <td>{{ $row['sku'] }}</td>
                    <td>{{ $row['meta'] }}</td>
                    <td class="fb-amount">{{ $row['amount'] }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @else
    <div class="fb-empty">{{ $fb['empty'] ?? 'Nothing to show.' }}</div>
    @endif
</div>

<div class="fb-note">
    <svg style="width:.9rem;height:.9rem" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
    </svg>
    All figures are deterministic database aggregates derived from recorded investigations and outcomes — no estimates are generated on this page. Showing up to the top 200 contributors.
</div>

</x-filament-panels::page>
