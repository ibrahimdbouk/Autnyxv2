<x-filament-widgets::widget>
    <x-ui.card title="What moved sales and why">
        @if(! $r)
            <x-ui.empty title="No sales week to compare yet">Once a full week of sales is loaded, it is compared with the same week last year (or the 4 weeks before).</x-ui.empty>
        @else
            <p class="ax-text-sm ax-mb-2">{{ $r['headline'] }}
                <span class="ax-faint ax-text-xs">Week of {{ \Illuminate\Support\Carbon::parse($r['period']['from'])->format('j M') }} vs {{ $r['comparison']['kind'] === 'yoy' ? 'the same week last year' : 'the 4 weeks before' }}.</span>
            </p>
            <x-ui.viz.waterfall :steps="$steps" :total="$total" :fmt="$fmt" label="Why sales moved" />
            @if($movers !== [])
                <table class="ax-table ax-mt-3">
                    <thead><tr><th>Biggest movers</th><th class="ax-num">Change</th><th>Mostly</th></tr></thead>
                    <tbody>
                        @foreach($movers as $m)
                            <tr><td>{{ $m['kind'] }} · {{ $m['name'] }}</td><td class="ax-num ax-nowrap">{{ $m['text'] }}</td><td class="ax-text-xs">{{ $m['main'] }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
            <p class="ax-faint ax-text-xs ax-mt-2">Computed from the data, not by AI. <a href="{{ $url }}">Open performance drivers</a></p>
        @endif
    </x-ui.card>
</x-filament-widgets::widget>
