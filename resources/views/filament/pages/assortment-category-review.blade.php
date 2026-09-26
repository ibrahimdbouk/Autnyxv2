<x-filament-panels::page>
<x-ui.card>
    @if($rows === [])
        <x-ui.empty title="No range decisions yet">
            Categories appear here once decisions are open to your team.
        </x-ui.empty>
    @else
        <div class="ax-scroll-x">
            <table class="ax-table">
                <thead>
                    <tr>
                        <th>Category</th><th class="ax-num">Adds</th><th class="ax-num">Delists</th><th class="ax-num">Keeps running out</th>
                        <th class="ax-num">Open value a year</th><th class="ax-num">Accepted</th><th class="ax-num">Measured so far</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $i => $r)
                        <tr wire:key="cat-{{ md5($r['category']) }}">
                            <td class="ax-fw-600"><a href="{{ $r['url'] }}" wire:navigate>{{ $r['category'] }}</a></td>
                            <td class="ax-num">{{ $r['adds'] ?: '—' }}</td>
                            <td class="ax-num">{{ $r['delists'] ?: '—' }}</td>
                            <td class="ax-num">{{ $r['stockouts'] ?: '—' }}</td>
                            <td class="ax-num">{{ \App\Support\Money::compact($r['open_value'], $currency) }}</td>
                            <td class="ax-num">{{ $r['accepted'] ?: '—' }}</td>
                            <td class="ax-num">{{ $r['measured'] != 0 ? \App\Support\Money::compact($r['measured'], $currency) : '—' }}</td>
                            <td style="white-space:nowrap">
                                <x-filament::button size="xs" color="gray" outlined icon="heroicon-o-arrow-down-tray"
                                    wire:click="downloadPackAt({{ $i }})">Review pack</x-filament::button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="ax-faint ax-text-xs ax-mt-2">Open value is the middle of each decision's range. Click a category to work its decisions; the review pack is an Excel file for the category meeting.</p>
    @endif
</x-ui.card>
</x-filament-panels::page>
