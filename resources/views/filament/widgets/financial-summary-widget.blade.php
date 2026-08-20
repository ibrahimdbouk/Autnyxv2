<x-filament-widgets::widget>
    @php
        $data = $this->getViewData();
        $urlAtRisk    = \App\Filament\Pages\FinancialBreakdown::getUrl(['metric' => 'outcome_at_risk']);
        $urlRecovered = \App\Filament\Pages\FinancialBreakdown::getUrl(['metric' => 'observed_recovery']);
        $urlRate      = \App\Filament\Pages\FinancialBreakdown::getUrl(['metric' => 'recovery_rate']);
        $urlFp        = \App\Filament\Pages\FinancialBreakdown::getUrl(['metric' => 'false_positives']);
    @endphp

    <style>
        a.wstat { text-decoration:none; cursor:pointer; transition:transform .15s ease, box-shadow .15s ease; display:block; }
        a.wstat:hover { transform:translateY(-2px); box-shadow:0 6px 16px rgba(0,0,0,.10); }
    </style>

    <div style="display:flex; flex-wrap:wrap; gap:1rem; width:100%;">

        <a href="{{ $urlAtRisk }}" class="wstat" style="flex:1; min-width:150px; border-radius:0.75rem; border:1px solid #fecaca; background:#fff1f2; padding:1rem; text-align:center;">
            <p style="font-size:0.7rem; font-weight:600; color:#dc2626; text-transform:uppercase; letter-spacing:0.05em; margin:0;">Revenue at Risk</p>
            <p style="font-size:1.75rem; font-weight:700; color:#991b1b; margin:0.25rem 0;">${{ number_format($data['total_at_risk'], 0) }}</p>
            <p style="font-size:0.7rem; color:#ef4444; margin:0;">AI-estimated across all outcomes</p>
        </a>

        <a href="{{ $urlRecovered }}" class="wstat" style="flex:1; min-width:150px; border-radius:0.75rem; border:1px solid #bbf7d0; background:#f0fdf4; padding:1rem; text-align:center;">
            <p style="font-size:0.7rem; font-weight:600; color:#16a34a; text-transform:uppercase; letter-spacing:0.05em; margin:0;">Observed Recovery</p>
            <p style="font-size:1.75rem; font-weight:700; color:#14532d; margin:0.25rem 0;">${{ number_format($data['total_recovered'], 0) }}</p>
            <p style="font-size:0.7rem; color:#22c55e; margin:0;">Analyst-confirmed</p>
        </a>

        <a href="{{ $urlRate }}" class="wstat" style="flex:1; min-width:150px; border-radius:0.75rem; border:1px solid #bfdbfe; background:#eff6ff; padding:1rem; text-align:center;">
            <p style="font-size:0.7rem; font-weight:600; color:#2563eb; text-transform:uppercase; letter-spacing:0.05em; margin:0;">Recovery Rate</p>
            <p style="font-size:1.75rem; font-weight:700; color:#1e40af; margin:0.25rem 0;">
                @if($data['recovery_rate'] !== null){{ $data['recovery_rate'] }}%@else—@endif
            </p>
            <p style="font-size:0.7rem; color:#3b82f6; margin:0;">Recovered / at risk</p>
        </a>

        <a href="{{ $urlFp }}" class="wstat" style="flex:1; min-width:150px; border-radius:0.75rem; border:1px solid #e5e7eb; background:#ffffff; padding:1rem; text-align:center;">
            <p style="font-size:0.7rem; font-weight:600; color:#6b7280; text-transform:uppercase; letter-spacing:0.05em; margin:0;">False Positives</p>
            <p style="font-size:1.75rem; font-weight:700; color:#374151; margin:0.25rem 0;">{{ $data['fp_count'] }}</p>
            <p style="font-size:0.7rem; color:#9ca3af; margin:0;">Investigations closed as FP</p>
        </a>

    </div>
</x-filament-widgets::widget>
