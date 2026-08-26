<x-filament-panels::page>
    <x-ui.section
        kicker="Track U · design system"
        title="Autnyx UI Kit"
        sub="Shared tokens + primitives. Everything below is dark-safe by construction and reused across pages via x-ui.* components." />

    {{-- Stat tiles --}}
    <div class="ax-grid ax-grid-4" style="margin-bottom:1.5rem">
        <x-ui.stat label="Open Anomalies" value="4,059" foot="12 high severity" href="#" />
        <x-ui.stat color="warning" label="Est. Value at Risk" value="AED 1.2M" foot="across open anomalies" />
        <x-ui.stat color="success" label="Recovered MTD" value="AED 412K" foot="▲ 18% vs last" trend="up" />
        <x-ui.stat color="danger" label="Stockout Risk" value="3,611" foot="needs review" />
    </div>

    <div class="ax-grid ax-grid-2" style="margin-bottom:1.5rem">
        {{-- Card + badges + money --}}
        <x-ui.card title="Detection rule">
            <x-slot:actions><x-ui.badge color="accent">core</x-ui.badge></x-slot:actions>

            <div class="ax-row" style="flex-wrap:wrap; gap:.4rem">
                <x-ui.badge color="high" dot>High</x-ui.badge>
                <x-ui.badge color="medium" dot>Medium</x-ui.badge>
                <x-ui.badge color="low" dot>Low</x-ui.badge>
                <x-ui.badge color="success">Resolved</x-ui.badge>
                <x-ui.badge color="info">In progress</x-ui.badge>
                <x-ui.badge>Neutral</x-ui.badge>
            </div>

            <hr class="ax-divider">

            <div class="ax-between"><span class="ax-muted">Lost sales at risk</span><x-ui.money :amount="-4000" currency="AED" :decimals="0" signed /></div>
            <div class="ax-between" style="margin-top:.4rem"><span class="ax-muted">Recovered</span><x-ui.money :amount="3200" currency="AED" :decimals="0" signed /></div>
        </x-ui.card>

        {{-- Accent card: causal chain + confidence meter --}}
        <x-ui.card variant="accent">
            <div class="ax-section-kicker">Root cause · inferred</div>
            <p style="font-weight:700; color:var(--ax-ink); margin:.4rem 0 .1rem">Chronic Supplier Under-Fill</p>
            <div class="ax-chain" style="margin:.5rem 0">
                <span class="ax-chain-node ax-chain-node--root">Supplier under-fill</span>
                <span class="ax-chain-arrow">→</span><span class="ax-chain-node">Stockout risk</span>
                <span class="ax-chain-arrow">→</span><span class="ax-chain-node">Sales drop</span>
            </div>
            <x-ui.badge color="success">verified · 84%</x-ui.badge>
            <x-ui.meter :value="84" color="success" style="margin-top:.6rem" />
        </x-ui.card>
    </div>

    {{-- Empty state --}}
    <x-ui.empty title="No open anomalies">
        Everything is within tolerance. New findings from the nightly scan will appear here.
    </x-ui.empty>
</x-filament-panels::page>
