<x-filament-widgets::widget>
    @php $data = $this->getViewData(); @endphp
    <div style="display:flex; flex-wrap:wrap; gap:1rem; width:100%;">

        <div style="flex:1; min-width:130px; border-radius:0.75rem; border:1px solid #fde68a; background:#fffbeb; padding:1rem; text-align:center;">
            <p style="font-size:0.7rem; font-weight:600; color:#d97706; text-transform:uppercase; letter-spacing:0.05em; margin:0;">Open</p>
            <p style="font-size:1.75rem; font-weight:700; color:#92400e; margin:0.25rem 0;">{{ $data['open'] }}</p>
            <p style="font-size:0.7rem; color:#d97706; margin:0;">Awaiting action</p>
        </div>

        <div style="flex:1; min-width:130px; border-radius:0.75rem; border:1px solid #bfdbfe; background:#eff6ff; padding:1rem; text-align:center;">
            <p style="font-size:0.7rem; font-weight:600; color:#2563eb; text-transform:uppercase; letter-spacing:0.05em; margin:0;">In Progress</p>
            <p style="font-size:1.75rem; font-weight:700; color:#1e40af; margin:0.25rem 0;">{{ $data['inProgress'] }}</p>
            <p style="font-size:0.7rem; color:#3b82f6; margin:0;">Being investigated</p>
        </div>

        <div style="flex:1; min-width:130px; border-radius:0.75rem; border:1px solid #fecaca; background:#fff1f2; padding:1rem; text-align:center;">
            <p style="font-size:0.7rem; font-weight:600; color:#dc2626; text-transform:uppercase; letter-spacing:0.05em; margin:0;">Critical</p>
            <p style="font-size:1.75rem; font-weight:700; color:#991b1b; margin:0.25rem 0;">{{ $data['critical'] }}</p>
            <p style="font-size:0.7rem; color:#ef4444; margin:0;">Open or in progress</p>
        </div>

        <div style="flex:1; min-width:130px; border-radius:0.75rem; border:1px solid #fde68a; background:#fffbeb; padding:1rem; text-align:center;">
            <p style="font-size:0.7rem; font-weight:600; color:#d97706; text-transform:uppercase; letter-spacing:0.05em; margin:0;">High Priority</p>
            <p style="font-size:1.75rem; font-weight:700; color:#92400e; margin:0.25rem 0;">{{ $data['high'] }}</p>
            <p style="font-size:0.7rem; color:#d97706; margin:0;">Open or in progress</p>
        </div>

        <div style="flex:1; min-width:130px; border-radius:0.75rem; border:1px solid #bbf7d0; background:#f0fdf4; padding:1rem; text-align:center;">
            <p style="font-size:0.7rem; font-weight:600; color:#16a34a; text-transform:uppercase; letter-spacing:0.05em; margin:0;">Resolved 7d</p>
            <p style="font-size:1.75rem; font-weight:700; color:#14532d; margin:0.25rem 0;">{{ $data['recentlyResolved'] }}</p>
            <p style="font-size:0.7rem; color:#22c55e; margin:0;">Last 7 days</p>
        </div>

        <div style="flex:1; min-width:130px; border-radius:0.75rem; border:1px solid #e5e7eb; background:#ffffff; padding:1rem; text-align:center;">
            <p style="font-size:0.7rem; font-weight:600; color:#6b7280; text-transform:uppercase; letter-spacing:0.05em; margin:0;">Avg Age</p>
            <p style="font-size:1.75rem; font-weight:700; color:#374151; margin:0.25rem 0;">
                @if($data['avgOpenHours'] !== null){{ $data['avgOpenHours'] }}h@else—@endif
            </p>
            <p style="font-size:0.7rem; color:#9ca3af; margin:0;">Open investigations</p>
        </div>

    </div>
</x-filament-widgets::widget>
