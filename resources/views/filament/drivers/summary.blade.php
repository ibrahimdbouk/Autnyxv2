<x-filament-widgets::widget>
    <x-ui.card :title="$r ? 'Week of ' . \Illuminate\Support\Carbon::parse($r['period']['from'])->format('j M Y') : 'Performance drivers'">
        @if(! $r)
            <x-ui.empty title="No sales to compare yet">Once sales are loaded, every week is compared with the same week last year (or the 4 weeks before) and split into what moved and why.</x-ui.empty>
        @else
            <p class="ax-mb-3">{{ $r['headline'] }}</p>
            <div class="ax-grid ax-grid-4">
                <x-ui.stat label="Sales this period" :value="$w->fmt($r['totals']['revenue_p'])"
                    :foot="\Illuminate\Support\Carbon::parse($r['period']['from'])->format('j M') . ' – ' . \Illuminate\Support\Carbon::parse($r['period']['to'])->format('j M')" />
                <x-ui.stat :label="$r['comparison']['kind'] === 'yoy' ? 'Same week last year' : '4 weeks before (per week)'" :value="$w->fmt($r['totals']['revenue_c'])"
                    :foot="\Illuminate\Support\Carbon::parse($r['comparison']['from'])->format('j M') . ' – ' . \Illuminate\Support\Carbon::parse($r['comparison']['to'])->format('j M Y')" />
                <x-ui.stat label="Change" :value="$w->chg($r['totals']['change'])" :color="$r['totals']['change'] >= 0 ? 'success' : 'danger'"
                    :foot="$r['totals']['change_pct'] !== null ? (($r['totals']['change_pct'] > 0 ? '+' : '') . $r['totals']['change_pct'] . '%') : null" />
                @if($r['totals']['traffic_basis'] === 'visitors')
                    <x-ui.stat label="Visitors" :value="number_format($r['totals']['visitors_p'])" :foot="'vs ' . number_format($r['totals']['visitors_c']) . ' (door counts)'" />
                @elseif($r['totals']['traffic_basis'] === 'receipts')
                    <x-ui.stat label="Receipts" :value="number_format($r['totals']['receipts_p'])" :foot="'vs ' . number_format($r['totals']['receipts_c'])" />
                @else
                    <x-ui.stat label="Units" :value="number_format($r['totals']['units_p'])" :foot="'vs ' . number_format($r['totals']['units_c'])" />
                @endif
            </div>
            @foreach($r['notes'] as $note)
                <p class="ax-faint ax-text-xs ax-mt-2">{{ $note }}</p>
            @endforeach
        @endif
    </x-ui.card>
</x-filament-widgets::widget>
