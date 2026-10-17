<x-filament-widgets::widget>
    <x-ui.card title="Where it came from">
        @if($groups === [])
            <x-ui.empty title="Nothing to show">{{ $has ? 'No store, department or product moved in this scope.' : 'No sales to compare yet.' }}</x-ui.empty>
        @else
            <div class="ax-grid ax-grid-3">
                @foreach($groups as $g)
                    <div>
                        <p class="ax-text-sm ax-mb-2"><strong>{{ $g['title'] }}</strong></p>
                        <table class="ax-table">
                            <thead><tr><th>Name</th><th class="ax-num">Change</th><th>Mostly</th></tr></thead>
                            <tbody>
                                @foreach($g['rows'] as $row)
                                    <tr>
                                        <td>
                                            @if($row['url'])<a href="{{ $row['url'] }}">{{ $row['name'] }}</a>@else{{ $row['name'] }}@endif
                                        </td>
                                        <td class="ax-num ax-nowrap">{{ $row['change_text'] }}</td>
                                        <td class="ax-text-xs">{{ $row['main_text'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endforeach
            </div>
            <p class="ax-faint ax-text-xs ax-mt-2">The largest movers each way. "Volume" is what stock, promotions, range and price do not explain. A product name links to its open investigation.</p>
        @endif
    </x-ui.card>
</x-filament-widgets::widget>
