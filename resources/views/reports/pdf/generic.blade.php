<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1f2937; font-size: 11px; margin: 0; }
        .head { border-bottom: 2px solid #6d28d9; padding-bottom: 10px; margin-bottom: 16px; }
        .brand { color: #6d28d9; font-size: 12px; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; }
        .title { font-size: 22px; font-weight: bold; margin: 4px 0 2px; }
        .meta { color: #6b7280; font-size: 10px; }
        .note { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; padding: 6px 9px; border-radius: 4px; font-size: 9.5px; margin-bottom: 14px; }

        .kpi-table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        .kpi-table td { width: 33%; padding: 8px; border: 1px solid #e5e7eb; vertical-align: top; }
        .kpi-label { color: #6b7280; font-size: 8.5px; text-transform: uppercase; letter-spacing: .5px; }
        .kpi-value { font-size: 16px; font-weight: bold; color: #111827; padding-top: 3px; }

        .section-heading { font-size: 12px; font-weight: bold; color: #374151; margin: 14px 0 6px; border-left: 3px solid #6d28d9; padding-left: 6px; }
        table.data { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        table.data th { background: #ede9fe; color: #4c1d95; text-align: left; padding: 5px 7px; font-size: 9px; text-transform: uppercase; letter-spacing: .4px; border: 1px solid #ddd6fe; }
        table.data td { padding: 5px 7px; font-size: 10px; border: 1px solid #eef2ff; }
        table.data tr:nth-child(even) td { background: #faf9ff; }
        .footer { margin-top: 20px; padding-top: 8px; border-top: 1px solid #e5e7eb; color: #9ca3af; font-size: 8.5px; text-align: center; }
    </style>
</head>
<body>
    <div class="head">
        <div class="brand">Autnyx · {{ $r['title'] }}</div>
        <div class="title">{{ $r['title'] }} Report</div>
        <div class="meta">
            {{ $r['tenant'] }} &nbsp;·&nbsp; Period: {{ $r['period'] }} &nbsp;·&nbsp; Generated {{ $r['generated_at'] }}
        </div>
    </div>

    @if(!empty($r['note']))
        <div class="note">{{ $r['note'] }}</div>
    @endif

    {{-- KPI grid: 3 per row --}}
    @if(!empty($r['kpis']))
    <table class="kpi-table">
        @foreach(array_chunk($r['kpis'], 3) as $chunk)
        <tr>
            @foreach($chunk as $kpi)
            <td>
                <div class="kpi-label">{{ $kpi['label'] }}</div>
                <div class="kpi-value">{{ $kpi['value'] }}</div>
            </td>
            @endforeach
            @for($i = count($chunk); $i < 3; $i++)<td></td>@endfor
        </tr>
        @endforeach
    </table>
    @endif

    {{-- Summary sections --}}
    @foreach($r['summary_sections'] ?? [] as $section)
        <div class="section-heading">{{ $section['heading'] }}</div>
        <table class="data">
            <thead>
                <tr>
                    @foreach($section['columns'] as $col)<th>{{ $col }}</th>@endforeach
                </tr>
            </thead>
            <tbody>
                @forelse($section['rows'] as $row)
                <tr>
                    @foreach($row as $cell)<td>{{ $cell }}</td>@endforeach
                </tr>
                @empty
                <tr><td colspan="{{ count($section['columns']) }}">No data for this period.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endforeach

    <div class="footer">
        Autnyx — deterministic report generated from recorded data. Figures are database aggregates for the selected period.
    </div>
</body>
</html>
