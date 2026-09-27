<x-filament-panels::page>
@php
    $money = static fn ($v) => \App\Support\Money::compact((float) $v, $currency);
    $range = static fn ($r) => is_array($r) ? ($money(min($r[0], $r[2])) === $money(max($r[0], $r[2])) ? $money($r[1]) : $money(min($r[0], $r[2])) . ' to ' . $money(max($r[0], $r[2]))) : '—';
    $pct = static fn ($v) => $v === null ? '—' : (($v >= 0 ? '+' : '−') . number_format(abs($v) * 100, 1) . '%');
@endphp
<div class="ax-stack">
    @if(! $live)
        <x-ui.card variant="accent">
            <p class="ax-m-0">Range plans are in review. Plans are shown to everyone once the top {{ \App\Services\Assortment\PlanService::SAMPLE }} have been checked with your category manager (Validation → Range plans){{ auth()->user()?->canManageUsers() ? '' : '.' }}@if(auth()->user()?->canManageUsers()) — you see them here as an admin.@endif</p>
        </x-ui.card>
    @endif

    <nav aria-label="Plan status">
        <x-filament::tabs>
            @foreach(\App\Filament\Pages\AssortmentPlans::TABS as $key => $label)
                <x-filament::tabs.item tag="a" :href="static::getUrl(['tab' => $key])" :active="$tab === $key">
                    {{ $label }} <span class="ax-faint">{{ $counts[$key] ?? 0 }}</span>
                </x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>
    </nav>

    <x-ui.card>
        @if($rows->isEmpty())
            <x-ui.empty title="No plans here">Plans are built after each run from the range decisions, one per store and category with something worth changing.</x-ui.empty>
        @else
            <div class="ax-scroll-x">
                <table class="ax-table">
                    <thead><tr><th>Store · category</th><th class="ax-num">Products</th><th>Changes</th><th class="ax-num">Category sales a year</th><th>Objective</th><th>Confidence</th><th>Status</th></tr></thead>
                    <tbody>
                        @foreach($rows as $p)
                            @php
                                $kinds = collect($p->actionable())->countBy('kind');
                                $i = $p->impact ?? [];
                            @endphp
                            <tr wire:key="plan-{{ $p->id }}">
                                <td style="min-width:13rem">
                                    <a class="ax-fw-600" href="{{ \App\Filament\Pages\AssortmentPlanPage::getUrl(['plan' => $p->id]) }}" wire:navigate>{{ $p->store?->name }} · {{ $p->category }}</a>
                                    <div class="ax-faint ax-text-xs">{{ \App\Services\Assortment\CategoryStrategy::roleLabel($p->role) }}</div>
                                </td>
                                <td class="ax-num" style="white-space:nowrap">{{ $p->current_count }} → {{ $p->proposed_count }}</td>
                                <td class="ax-text-sm">
                                    @if(! $p->feasible)
                                        <span style="color:var(--ax-danger-fg)">No feasible range under the limits</span>
                                    @else
                                        {{ collect([
                                            ($kinds['add'] ?? 0) ? $kinds['add'] . ' add' : null,
                                            ($kinds['delist'] ?? 0) ? $kinds['delist'] . ' delist' : null,
                                            ($kinds['swap'] ?? 0) ? $kinds['swap'] . ' swap' : null,
                                            ($kinds['recover'] ?? 0) ? $kinds['recover'] . ' stock fix' : null,
                                        ])->filter()->implode(' · ') }}
                                    @endif
                                </td>
                                <td class="ax-num" style="white-space:nowrap">
                                    @if(isset($p->measurement['uplift_per_year']))
                                        Measured {{ $money($p->measurement['uplift_per_year']) }}
                                    @else
                                        {{ $range($i['sales'] ?? null) }}
                                        <div class="ax-faint ax-text-xs">Estimated · {{ $pct($i['sales_pct'][1] ?? null) }}</div>
                                    @endif
                                </td>
                                <td>{{ \App\Services\Assortment\CategoryStrategy::objectiveLabel($p->objective) }}</td>
                                <td>{{ ucfirst($p->confidence_tier) }}</td>
                                <td class="ax-text-sm">{{ \App\Models\AssortmentPlan::STATUSES[$p->status] ?? $p->status }}@if($p->assignee) · {{ $p->assignee->name }}@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="ax-faint ax-text-xs ax-mt-2">A plan takes the range decisions for one store and category and chooses them together: each change is valued after what it takes from, or gives to, the rest of the shelf as it stands. Figures are estimates, a year, until measured.</p>
        @endif
    </x-ui.card>
</div>
</x-filament-panels::page>
