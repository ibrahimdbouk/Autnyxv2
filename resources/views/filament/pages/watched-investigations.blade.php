<x-filament-panels::page>
    @php $rows = $this->getRows(); @endphp

    <style>
        .wi-table { width:100%; border-collapse:separate; border-spacing:0; background:#fff; border:1px solid #e5e7eb; border-radius:.875rem; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.06); }
        .wi-table th { text-align:left; font-size:.7rem; text-transform:uppercase; letter-spacing:.05em; color:#6b7280; padding:.7rem 1rem; background:#f9fafb; border-bottom:1px solid #e5e7eb; }
        .wi-table td { padding:.8rem 1rem; border-bottom:1px solid #f3f4f6; font-size:.82rem; vertical-align:top; }
        .wi-badge { display:inline-block; padding:.15rem .55rem; border-radius:9999px; font-size:.7rem; font-weight:700; text-transform:capitalize; }
        .b-danger{background:#fee2e2;color:#991b1b;} .b-warning{background:#fef3c7;color:#92400e;}
        .b-info{background:#dbeafe;color:#1e40af;} .b-success{background:#dcfce7;color:#166534;} .b-gray{background:#f3f4f6;color:#374151;}
        .wi-link { color:#6d28d9; font-weight:600; text-decoration:none; }
        .wi-link:hover { text-decoration:underline; }
        .wi-empty { background:#fff; border:1px solid #e5e7eb; border-radius:.875rem; padding:2rem; text-align:center; color:#6b7280; }
        .wi-btn { font-size:.72rem; color:#dc2626; background:none; border:1px solid #fecaca; border-radius:.4rem; padding:.25rem .5rem; cursor:pointer; }
        .wi-btn:hover { background:#fef2f2; }
    </style>

    @php
        $statusClass = fn($s) => match($s){ 'open'=>'b-warning','in_progress'=>'b-info','resolved'=>'b-success','closed'=>'b-gray', default=>'b-gray' };
        $prioClass   = fn($p) => match($p){ 'critical'=>'b-danger','high'=>'b-warning','medium'=>'b-info', default=>'b-gray' };
    @endphp

    @if($rows->isEmpty())
        <div class="wi-empty">
            You aren't watching any investigations yet. Open an investigation and choose <strong>Watch</strong> to be notified when something meaningful changes.
        </div>
    @else
        <table class="wi-table">
            <thead>
                <tr>
                    <th>Investigation</th>
                    <th>Status</th>
                    <th>Priority</th>
                    <th>Revenue at risk</th>
                    <th>Last meaningful change</th>
                    <th>Next expected</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                    @php $inv = $row['investigation']; @endphp
                    <tr>
                        <td>
                            <a class="wi-link" href="{{ \App\Filament\Resources\InvestigationResource::getUrl('investigate', ['record' => $inv->id]) }}">
                                {{ \Illuminate\Support\Str::limit($inv->title, 60) }}
                            </a>
                            @if($row['via_team'])
                                <div style="font-size:.68rem;color:#9ca3af;">via team: {{ $row['via_team'] }}</div>
                            @endif
                        </td>
                        <td><span class="wi-badge {{ $statusClass($row['status']) }}">{{ str_replace('_',' ',$row['status']) }}</span></td>
                        <td><span class="wi-badge {{ $prioClass($row['priority']) }}">{{ $row['priority'] }}</span></td>
                        <td>{{ $row['revenue_at_risk'] !== null ? \App\Support\Money::format($row['revenue_at_risk'], \Filament\Facades\Filament::getTenant()?->currency, 0) : '—' }}</td>
                        <td>
                            {{ $row['last_change'] ? \Illuminate\Support\Carbon::parse($row['last_change'])->diffForHumans() : '—' }}
                            @if($row['last_message'])
                                <div style="font-size:.68rem;color:#9ca3af;">{{ \Illuminate\Support\Str::limit($row['last_message'], 60) }}</div>
                            @endif
                        </td>
                        <td>{{ $row['next_expected'] ?? '—' }}</td>
                        <td>
                            @if(! $row['via_team'])
                                <button type="button" class="wi-btn" wire:click="unwatch({{ $inv->id }})">Unwatch</button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-filament-panels::page>
