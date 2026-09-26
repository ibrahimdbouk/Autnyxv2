{{-- The Assortment app's dashboard. While the engine runs in shadow mode it
     shows only data readiness — range decisions appear once they have been
     checked for this organisation (the validation gate). --}}
<x-ui.section
    kicker="Assortment"
    title="Range data readiness"
    :sub="$asOf ? 'Based on data up to ' . \Illuminate\Support\Carbon::parse($asOf)->format('j M Y') : null" />

@if($checks !== [])
    <div class="ax-grid ax-grid-4">
        @foreach($checks as $c)
            <x-ui.stat :label="$c['label']" :value="$c['value']" :color="$c['color']" :foot="$c['foot']" />
        @endforeach
    </div>
@endif

<x-ui.card>
    @if($run === null)
        <x-ui.empty title="Assortment is being set up">
            The first run happens overnight. It works out which products each store carries and which stores are similar enough to compare.
        </x-ui.empty>
    @elseif($reason)
        <x-ui.empty title="Waiting for data">
            {{ ucfirst($reason) }}.
        </x-ui.empty>
    @else
        <x-ui.empty title="Range decisions are being checked">
            Recommendations to add products, drop slow sellers and fix products that keep running out are prepared every night. They appear here once they have been reviewed with your team.
        </x-ui.empty>
    @endif
</x-ui.card>
