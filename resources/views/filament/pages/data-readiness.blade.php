<x-filament-panels::page>
    @php($r = $this->getReadiness())
    @php($overall = $r['overall'])
    @php($tone = ['green' => ['#16794b', '#e7f6ee', 'DETECTION READY'], 'amber' => ['#8a5a00', '#fdf3e0', 'PROMOTING WITH EXCEPTIONS'], 'red' => ['#a3271b', '#fdeceb', 'DATASETS BLOCKED']])
    @php($t = $tone[$overall] ?? $tone['green'])

    <div style="border:1px solid #e5e7eb;border-radius:1rem;padding:1.25rem 1.5rem;margin-bottom:1.25rem;background:{{ $t[1] }}">
        <div style="font-size:.72rem;font-weight:700;letter-spacing:.08em;color:{{ $t[0] }}">DATA READINESS</div>
        <div style="font-size:1.5rem;font-weight:800;color:{{ $t[0] }};margin-top:.25rem">{{ $t[2] }}</div>
        <div style="font-size:.9rem;color:#4b5563;margin-top:.35rem;max-width:70ch">
            Clean data promotes automatically and detection runs unattended. A blocked dataset only pauses its own
            detection rules — everything else keeps working.
        </div>
    </div>

    @if (empty($r['datasets']))
        <div style="color:#6b7280;padding:2rem;text-align:center;border:1px dashed #e5e7eb;border-radius:.75rem">
            No batches ingested yet. Once data flows through the firewall, per-dataset readiness shows here.
        </div>
    @else
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:1rem">
            @foreach ($r['datasets'] as $d)
                @php($c = ['green' => ['#16794b', '#e7f6ee', 'READY'], 'amber' => ['#8a5a00', '#fdf3e0', 'AMBER'], 'red' => ['#a3271b', '#fdeceb', 'BLOCKED']][$d['state']] ?? ['#374151', '#f3f4f6', strtoupper($d['state'])])
                <div style="border:1px solid #e5e7eb;border-radius:.75rem;padding:1rem 1.1rem;background:#fff">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:.5rem">
                        <div style="font-weight:700;color:#111827">{{ \App\Models\Import::dataTypeLabels()[$d['data_type']] ?? $d['data_type'] }}</div>
                        <span style="font-size:.68rem;font-weight:800;letter-spacing:.05em;color:{{ $c[0] }};background:{{ $c[1] }};padding:.2rem .5rem;border-radius:.4rem">{{ $c[2] }}</span>
                    </div>
                    <div style="font-size:.85rem;color:#4b5563;margin-top:.5rem;line-height:1.4">{{ $d['decision'] }}</div>
                    <div style="display:flex;gap:1.25rem;margin-top:.6rem;font-size:.82rem">
                        <span style="color:#16794b"><strong>{{ number_format($d['promoted']) }}</strong> promoted</span>
                        <span style="color:#a3271b"><strong>{{ number_format($d['quarantined']) }}</strong> quarantined</span>
                    </div>
                    @if ($d['top_reason'])
                        <div style="font-size:.78rem;color:#6b7280;margin-top:.35rem">Top issue: {{ $d['top_reason'] }}</div>
                    @endif
                    @if (! empty($d['rules']))
                        <div style="font-size:.72rem;color:#6b7280;margin-top:.6rem;border-top:1px solid #f3f4f6;padding-top:.5rem">
                            {{ $d['ready'] ? 'Detection running:' : 'Detection paused:' }}
                            <span style="color:{{ $d['ready'] ? '#16794b' : '#a3271b' }}">{{ implode(', ', array_slice($d['rules'], 0, 6)) }}{{ count($d['rules']) > 6 ? '…' : '' }}</span>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
