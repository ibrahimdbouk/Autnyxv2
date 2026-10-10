<x-filament-panels::page>
@if($closed)
    <x-ui.empty title="The Decision Studio opens when range decisions go live">
        Range decisions are still in review. Once they pass, you can try changes to any store's range here and make one the plan.
    </x-ui.empty>
@elseif(! $ctx)
    <x-ui.empty title="Nothing to work on yet">No store in your scope carries a range yet. Ranges appear after the first Assortment run.</x-ui.empty>
@else
@php
    $money = static fn ($v) => \App\Support\Money::compact((float) $v, $currency);
    $signed = static fn ($v) => ((float) $v >= 0 ? '+' : '−') . \App\Support\Money::compact(abs((float) $v), $currency);
    $range = static fn ($r) => is_array($r) && count($r) === 3
        ? ($signed(min($r[0], $r[2])) === $signed(max($r[0], $r[2])) ? $signed($r[1]) : $signed(min($r[0], $r[2])) . ' to ' . $signed(max($r[0], $r[2])))
        : '—';
    $pct = static fn ($v) => $v === null ? '—' : (((float) $v >= 0 ? '+' : '−') . number_format(abs((float) $v) * 100, 1) . '%');
    $pctRange = static fn ($r) => is_array($r) ? ($pct(min($r[0], $r[2])) . ' to ' . $pct(max($r[0], $r[2]))) : '—';
    $share = static fn ($v) => $v === null ? '—' : (int) round((float) $v * 100) . '%';
    $i = $r['impact'];
    $s = $r['summary'];
    $c = $r['constraints'];
    $labels = \App\Models\AssortmentPlan::CHANGE_LABELS;
    $kindColor = ['add' => 'success', 'delist' => 'warning', 'swap' => 'info', 'recover' => 'danger'];
    $flagLabel = ['delist' => 'Weak: delist proposed', 'recover' => 'Keeps running out', 'add' => 'Add proposed'];
    $acting = array_values(array_filter($r['changes'], fn ($ch) => $ch['kind'] !== 'protect'));
    $mine = array_values(array_filter($acting, fn ($ch) => $ch['kind'] !== 'recover'));
    $fixes = array_values(array_filter($acting, fn ($ch) => $ch['kind'] === 'recover'));
    $basisLabel = ['observed' => 'Observed', 'assumed' => 'Assumed from product similarity', 'insufficient' => 'Not enough evidence'];
    $m2 = static fn ($v) => $v === null ? '—' : number_format((float) $v, 2) . ' m';
    $metresMode = ($space['basis'] ?? 'count') !== 'count';
    $facingsMode = ($space['basis'] ?? 'count') === 'facings';
    $sm = $i['space'] ?? null;
@endphp

<div class="ax-stack">
    {{-- The shelf --}}
    <x-ui.card>
        <div class="ax-grid ax-grid-2">
            <label class="ax-stack" style="gap:.25rem">
                <span class="ax-text-xs ax-faint">Store</span>
                <select class="fi-select-input" wire:model.live="store" aria-label="Store">
                    @foreach($stores as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="ax-stack" style="gap:.25rem">
                <span class="ax-text-xs ax-faint">Category</span>
                <select class="fi-select-input" wire:model.live="category" aria-label="Category">
                    @foreach($categories as $cat)
                        <option value="{{ $cat['category'] }}">{{ $cat['category'] }} · {{ number_format($cat['skus']) }} products{{ $cat['open'] ? ' · ' . $cat['open'] . ' to decide' : '' }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        <div class="ax-row ax-wrap ax-mt-3" style="gap:.5rem">
            <x-ui.badge color="info">{{ \App\Services\Assortment\CategoryStrategy::roleLabel($strategy['role']) }}</x-ui.badge>
            <x-ui.badge>Objective: {{ \App\Services\Assortment\CategoryStrategy::objectiveLabel($strategy['objective']) }}</x-ui.badge>
            @if($ctx['peer_size'])
                <x-ui.badge>Compared with {{ $ctx['peer_size'] - 1 }} similar stores{{ $ctx['peer_label'] ? ' (' . $ctx['peer_label'] . ')' : '' }}</x-ui.badge>
            @endif
            <span class="ax-faint ax-text-xs">Data up to {{ \Illuminate\Support\Carbon::parse($ctx['as_of'])->format('j M Y') }}</span>
            @if($plan)
                <span class="ax-text-xs ax-muted">· This shelf's plan: {{ \App\Models\AssortmentPlan::STATUSES[$plan->status] ?? $plan->status }}{{ $plan->fromStudio() ? ' (made here)' : '' }}@if($planUrl) — <a href="{{ $planUrl }}" wire:navigate>open it</a>@endif</span>
            @endif
        </div>
    </x-ui.card>

    <div class="ax-row ax-wrap" role="tablist" style="gap:.25rem">
        @foreach(['workspace' => 'Workspace', 'compare' => 'Compare scenarios'] as $k => $l)
            <x-filament::button size="sm" :color="$tab === $k ? 'primary' : 'gray'" :outlined="$tab !== $k" wire:click="$set('tab', '{{ $k }}')" role="tab" aria-selected="{{ $tab === $k ? 'true' : 'false' }}">{{ $l }}</x-filament::button>
        @endforeach
    </div>

@if($tab === 'compare')
    {{-- Scenarios side by side --}}
    <x-ui.card title="Scenarios side by side">
        <div class="ax-scroll-x">
            <table class="ax-table">
                <thead>
                    <tr>
                        <th></th>
                        @foreach($columns as $col)
                            <th class="ax-num" style="min-width:9.5rem">
                                <span class="ax-ink">{{ $col['label'] }}</span><br>
                                <span class="ax-faint ax-text-xs" style="font-weight:400;text-transform:none;letter-spacing:0">{{ $col['about'] }}</span>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @php
                        $metrics = [
                            'Products'                 => fn ($x) => ($x['current_count'] ?? '—') . ' → ' . ($x['count'] ?? '—'),
                            'Changes'                  => fn ($x) => ($x['changes'] ?? 0) . (($x['recovers'] ?? 0) ? ' + ' . $x['recovers'] . ' stock fix(es)' : ''),
                            'Category sales a year'    => fn ($x) => $range($x['sales'] ?? null),
                            'Gross margin a year'      => fn ($x) => $range($x['margin'] ?? null),
                            'Sales back on the shelf'  => fn ($x) => ($x['availability'] ?? 0) > 0 ? $money($x['availability']) : '—',
                            'Stock tied up, at cost'   => fn ($x) => $signed($x['stock'] ?? 0),
                            'Shelf used'               => fn ($x) => ($x['metres'] ?? null) ? $m2($x['used_after']) . ' of ' . $m2($x['metres']) : 'Counted in products',
                            'Facings changed'          => fn ($x) => ($x['facings'] ?? 0) ? (string) $x['facings'] : '—',
                            ($metresMode ? 'Margin per metre' : 'Margin per product slot') => fn ($x) => $pct($x['space_pct'] ?? null),
                            'Sales lost by changes'    => fn ($x) => $range($x['lost'] ?? null),
                            'Sales gained by changes'  => fn ($x) => $range($x['gained'] ?? null),
                            'Confidence'               => fn ($x) => ($x['changes'] ?? 0) + ($x['recovers'] ?? 0) ? ucfirst($x['confidence_tier'] ?? '—') : '—',
                            'Limits'                   => fn ($x) => ! ($x['feasible'] ?? true) ? 'Cannot be met' : (($x['violations'] ?? []) ? 'Breaks a limit' : 'Within limits'),
                        ];
                    @endphp
                    @foreach($metrics as $label => $f)
                        <tr>
                            <td class="ax-fw-600">{{ $label }}</td>
                            @foreach($columns as $col)
                                <td class="ax-num">{{ $f($col['summary']) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                    <tr>
                        <td></td>
                        @foreach($columns as $col)
                            <td class="ax-num">
                                @if($col['kind'] === 'preset')
                                    <button type="button" class="ax-btn" wire:click="loadPreset('{{ $col['key'] }}')">Load</button>
                                @elseif($col['kind'] === 'saved')
                                    <button type="button" class="ax-btn" wire:click="loadScenario({{ $col['id'] }})">Load</button>
                                    @if($col['mine'])
                                        <button type="button" class="ax-btn" wire:click="deleteScenario({{ $col['id'] }})" wire:confirm="Delete this scenario?">Delete</button>
                                    @endif
                                @endif
                            </td>
                        @endforeach
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="ax-faint ax-text-xs ax-mt-2">Every figure is <strong>Simulated</strong>: what the change would do on the shelf as it stands, a year, as a range — not a forecast. Scenarios compare as they were saved. Load one to edit it, then make it the plan from the workspace.</p>
    </x-ui.card>
@else
    @if($loaded)
        <p class="ax-text-sm ax-muted">Loaded: <strong class="ax-ink">{{ $loaded }}</strong> — change anything; the figures follow.</p>
    @endif
    @foreach($r['violations'] ?? [] as $v)
        <div class="ax-card ax-card--soft" role="alert" style="border-color:var(--ax-danger-line)"><div class="ax-card-body ax-text-sm ax-between ax-wrap" style="color:var(--ax-danger-fg)">
            <span><strong>Breaks a limit.</strong> {{ $v['why'] }} It can be explored, but not made the plan.</span>
            @if($v['key'] === 'metres' && ($r['room_cuts'] ?? null) !== null)
                <button type="button" class="ax-btn" wire:click="makeRoom">Make room: cut over-spaced facings</button>
            @elseif($v['key'] === 'metres')
                <span class="ax-text-xs">Cutting over-spaced facings cannot make enough room: take a product off, or try a longer shelf.</span>
            @endif
        </div></div>
    @endforeach
    @foreach($r['skipped'] ?? [] as $sk)
        <p class="ax-text-xs ax-muted">Not applied: {{ $ctx['products'][$sk['sku']]['name'] ?? $sk['sku'] }} — {{ $sk['why'] }}</p>
    @endforeach

    <div @class(['ax-grid', 'ax-grid-3' => $metresMode, 'ax-grid-5' => ! $metresMode])>
        <x-ui.stat label="Products" :value="$s['current_count'] . ' → ' . $s['count']"
            :foot="($metresMode ? 'At least ' . $c['min_size'] . ($c['max_size'] ? ', at most ' . $c['max_size'] : '') : 'Limit ' . $c['min_size'] . '–' . $c['max_size']) . ' · ' . count($mine) . ' change(s)'" />
        @if($metresMode && $sm)
            <x-ui.stat label="Shelf used" :value="$m2($sm['used_before']) . ' → ' . $m2($sm['used_after'])"
                :color="$sm['used_after'] > $sm['metres'] + 0.0001 ? 'danger' : null"
                :foot="'of ' . $m2($sm['metres']) . (($space['metres_source'] ?? null) === 'scenario' ? ' (tried here)' : '') . ' · Simulated'" />
        @endif
        <x-ui.stat label="Category sales a year" :value="$range($i['sales'])" :foot="'Simulated · ' . $pctRange($i['sales_pct']) . ' of ' . $money($i['baseline']['sales'])" />
        <x-ui.stat :label="($r['margin_known'] ?? false) ? 'Gross margin a year' : 'Margin a year (no cost prices: sales)'" :value="$range($i['margin'])"
            :foot="'Simulated' . ($i['margin_pct'] ? ' · ' . $pctRange($i['margin_pct']) : '')" />
        <x-ui.stat label="Sales back on the shelf" :value="$i['availability'] > 0 ? $money($i['availability']) : '—'"
            :foot="count($fixes) . ' stock fix(es) · Estimated, before what buyers take elsewhere'" />
        <x-ui.stat label="Stock tied up, at cost" :value="$signed($i['stock'])"
            :foot="'Simulated' . ($i['stock_pct'] !== null ? ' · ' . $pct($i['stock_pct']) : ' · no stock file') . ' · margin per ' . ($metresMode ? 'metre ' : 'product ') . $pct($i['space_margin_pct'] ?? null)" />
    </div>

    <x-ui.card title="How the range is judged">
        <div class="ax-grid ax-grid-4">
            <label class="ax-stack" style="gap:.25rem">
                <span class="ax-text-xs ax-faint">Objective</span>
                <select class="fi-select-input" wire:model.live="objective" aria-label="Objective">
                    <option value="">Category's own ({{ \App\Services\Assortment\CategoryStrategy::objectiveLabel($ctx['strategy']['objective']) }})</option>
                    @foreach($objectives as $k => $l)
                        <option value="{{ $k }}">{{ $l }}</option>
                    @endforeach
                </select>
            </label>
            <label class="ax-stack" style="gap:.25rem">
                <span class="ax-text-xs ax-faint">Room to grow</span>
                <select class="fi-select-input" wire:model.live="room" aria-label="Room to grow">
                    <option value="">Category's own ({{ $ctx['strategy']['room'] > 0 ? '+' . (int) round($ctx['strategy']['room'] * 100) . '%' : 'hold the count' }})</option>
                    @foreach($rooms as $k => $l)
                        <option value="{{ $k }}">{{ $l }}</option>
                    @endforeach
                </select>
            </label>
            <label class="ax-stack" style="gap:.25rem">
                <span class="ax-text-xs ax-faint">Most products</span>
                <input type="number" min="1" class="fi-input" wire:model.live.debounce.500ms="maxSize" placeholder="{{ $c['max_size'] }}" aria-label="Most products">
            </label>
            <label class="ax-stack" style="gap:.25rem">
                <span class="ax-text-xs ax-faint">Fewest products</span>
                <input type="number" min="1" class="fi-input" wire:model.live.debounce.500ms="minSize" placeholder="{{ $c['min_size'] }}" aria-label="Fewest products">
            </label>
        </div>
        <div class="ax-row ax-wrap ax-mt-3" style="gap:.35rem">
            <span class="ax-text-xs ax-faint">Start from the optimiser's plan:</span>
            @foreach(\App\Services\Assortment\StudioService::PRESETS as $k => $p)
                <button type="button" class="ax-btn" wire:click="loadPreset('{{ $k }}')" title="{{ $p['about'] }}">{{ $p['label'] }}</button>
            @endforeach
        </div>
        @if($metresMode)
            <div class="ax-row ax-wrap ax-mt-3" style="gap:.5rem">
                <label class="ax-row" style="gap:.5rem">
                    <span class="ax-text-xs ax-faint">Shelf metres</span>
                    <input type="number" min="0.1" step="0.1" class="fi-input" style="max-width:8rem" wire:model.live.debounce.500ms="metres" placeholder="{{ number_format((float) ($ctx['shelf']['space']['metres'] ?? 0), 2) }}" aria-label="Shelf metres">
                </label>
                <span class="ax-text-xs ax-faint">{{ \App\Services\Assortment\ShelfSpace::LABELS[$space['basis']] }}{{ ($ctx['shelf']['space']['metres_source'] ?? null) === 'format' ? ' (the store format\'s metres)' : '' }}.{{ ($space['note'] ?? null) ? ' ' . $space['note'] : '' }}</span>
            </div>
            <p class="ax-faint ax-text-xs ax-mt-2">Changes are fitted within the shelf's metres.{{ $facingsMode ? ' Facings can be set per product; the optimiser cuts facings of over-spaced products (more of the shelf than of sales) to make room, never below their fewest. Facings alone are assumed not to change sales.' : '' }} The optimiser only uses the engine's decisions; changes you make yourself are valued the same way and labelled as yours.</p>
        @else
            <p class="ax-faint ax-text-xs ax-mt-2">{{ $space['note'] ?? 'No shelf metres for this store and category.' }} Load shelf metres and product widths in Range &amp; Must-Stock to fit changes in metres. The optimiser only uses the engine's decisions; changes you make yourself are valued the same way and labelled as yours.</p>
        @endif
    </x-ui.card>

    <div class="ax-split">
        <div class="ax-stack">
            <x-ui.card title="The changes being tried, in order">
                @if($acting === [])
                    <p class="ax-text-sm ax-muted">No changes yet. Take a product off the range, add one from the opportunities, or start from the optimiser's plan above.</p>
                @else
                    <div class="ax-scroll-x">
                        <table class="ax-table">
                            <thead><tr><th style="min-width:9rem">Change</th><th>What it does</th><th class="ax-num">Category sales a year</th><th></th></tr></thead>
                            <tbody>
                                @foreach($mine as $ch)
                                    @php $idx = collect($picks)->search(fn ($p) => $p['sku'] === $ch['sku'] || $p['sku'] === ($ch['out_sku'] ?? null)); @endphp
                                    <tr wire:key="ch-{{ $ch['key'] }}">
                                        <td>
                                            <x-ui.badge :color="$kindColor[$ch['kind']] ?? null">{{ $labels[$ch['kind']] ?? $ch['kind'] }}</x-ui.badge>
                                            <div class="ax-fw-600 ax-mt-1"><button type="button" class="ax-ink" wire:click="focusOn(@js($ch['sku']))">{{ $ch['name'] }}</button></div>
                                            <div class="ax-faint ax-text-xs">{{ $ch['kind'] === 'facings' ? $ch['from'] . ' → ' . $ch['to'] . ' facings · ' : '' }}{{ ($ch['source'] ?? 'engine') === 'user' ? 'Your change · Simulated' : 'Proposed by the engine · Estimated' }}</div>
                                        </td>
                                        <td class="ax-text-sm ax-muted">{{ $ch['why'] }}</td>
                                        <td class="ax-num">{{ $range($ch['sales']) }}</td>
                                        <td>
                                            @if($ch['kind'] === 'facings')
                                                <button type="button" class="ax-btn" wire:click="resetFacings(@js($ch['sku']))" aria-label="Undo the facings of {{ $ch['name'] }}">Undo</button>
                                            @elseif($idx !== false)<button type="button" class="ax-btn" wire:click="undo({{ $idx }})" aria-label="Undo {{ $ch['name'] }}">Undo</button>@endif
                                        </td>
                                    </tr>
                                @endforeach
                                @foreach($fixes as $ch)
                                    <tr wire:key="fx-{{ $ch['key'] }}">
                                        <td><x-ui.badge color="danger">{{ $labels['recover'] }}</x-ui.badge><div class="ax-fw-600 ax-mt-1"><button type="button" class="ax-ink" wire:click="focusOn(@js($ch['sku']))">{{ $ch['name'] }}</button></div></td>
                                        <td class="ax-text-sm ax-muted">{{ $ch['why'] }}</td>
                                        <td class="ax-num">{{ $range($ch['sales']) }}</td>
                                        <td><button type="button" class="ax-btn" wire:click="toggleSkip({{ \Illuminate\Support\Js::from($ch['sku']) }})">Leave out</button></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-ui.card>

            <x-ui.card title="The range today">
                <div class="ax-between ax-wrap ax-mb-2">
                    <input type="search" class="fi-input" style="max-width:18rem" wire:model.live.debounce.300ms="search" placeholder="Find a product" aria-label="Find a product">
                    <span class="ax-faint ax-text-xs">{{ number_format($ctx['shelf']['baseline']['count']) }} products · sales a year from the last 90 days <x-ui.badge color="observed">Observed</x-ui.badge></span>
                </div>
                <div class="ax-scroll-x">
                    <table class="ax-table">
                        <thead><tr><th style="min-width:12rem">Product</th><th class="ax-num">Sales a year</th><th class="ax-num">Margin</th>@if($metresMode)<th class="ax-num">{{ $facingsMode ? 'Facings' : 'Width' }}</th>@endif<th>Note</th><th></th></tr></thead>
                        <tbody>
                            @forelse($rows as $row)
                                <tr wire:key="rg-{{ md5($row['sku']) }}" @class(['ax-row-hl' => $focus === $row['sku']])>
                                    <td @class(['ax-off' => $row['off']])>
                                        <button type="button" class="ax-ink ax-fw-600" wire:click="focusOn(@js($row['sku']))">{{ $row['name'] }}</button>
                                        <div class="ax-faint ax-text-xs ax-mono">{{ $row['sku'] }}</div>
                                    </td>
                                    <td class="ax-num">{{ $row['sales'] === null ? '—' : $money($row['sales']) }}</td>
                                    <td class="ax-num">{{ $row['margin_rate'] === null ? '—' : $share($row['margin_rate']) }}</td>
                                    @if($metresMode)
                                        <td class="ax-num" style="white-space:nowrap">
                                            @if($facingsMode && ! $row['off'] && ! $row['new'] && $row['facings'] !== null)
                                                <button type="button" class="ax-btn" wire:click="facing(@js($row['sku']), -1)" aria-label="One facing fewer for {{ $row['name'] }}" @disabled($row['facings'] <= (int) round((float) ($row['min_facings'] ?? 1)))>−</button>
                                                <span @class(['ax-fw-700' => $row['facings'] !== $row['facings_today']])>{{ $row['facings'] }}</span>
                                                <button type="button" class="ax-btn" wire:click="facing(@js($row['sku']), 1)" aria-label="One facing more for {{ $row['name'] }}" @disabled($row['max_facings'] && $row['facings'] >= $row['max_facings'])>+</button>
                                            @elseif($facingsMode)
                                                {{ $row['facings'] ?? '—' }}
                                            @else
                                                {{ $row['width'] ? number_format($row['width'] * 100, 0) . ' cm' : '—' }}
                                            @endif
                                        </td>
                                    @endif
                                    <td class="ax-text-xs">
                                        @if($row['new'])<x-ui.badge color="success">Added</x-ui.badge>@endif
                                        @if($row['off'])<x-ui.badge color="warning">Taken off</x-ui.badge>@endif
                                        @if($row['flag'] && ! $row['new'])<x-ui.badge :color="$row['flag'] === 'recover' ? 'danger' : 'warning'">{{ $flagLabel[$row['flag']] ?? $row['flag'] }}</x-ui.badge>@endif
                                        @if($row['protected'])<span class="ax-muted">{{ $row['protected'] }}</span>@endif
                                    </td>
                                    <td style="white-space:nowrap">
                                        @if($row['new'] || $row['off'])
                                            <button type="button" class="ax-btn" wire:click="{{ $row['new'] ? 'remove' : 'add' }}({{ \Illuminate\Support\Js::from($row['sku']) }})">Put back</button>
                                        @elseif($row['protected'])
                                            @if($row['default_protected'] || in_array($row['sku'], $protect, true))
                                                <button type="button" class="ax-btn" wire:click="toggleProtect({{ \Illuminate\Support\Js::from($row['sku']) }})" title="{{ 'Allow it to be taken off in this scenario' }}">Unprotect</button>
                                            @endif
                                        @else
                                            <button type="button" class="ax-btn" wire:click="remove({{ \Illuminate\Support\Js::from($row['sku']) }})" aria-label="Take {{ $row['name'] }} off the range">Take off</button>
                                            <button type="button" class="ax-btn" wire:click="toggleProtect({{ \Illuminate\Support\Js::from($row['sku']) }})">{{ in_array($row['sku'], $unprotect, true) ? 'Protect again' : 'Protect' }}</button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="{{ $metresMode ? 6 : 5 }}" class="ax-muted ax-text-sm">No product matches.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($total > count($rows) && trim($search) === '')
                    <div class="ax-mt-2"><button type="button" class="ax-btn" wire:click="more">Show {{ min(100, $total - count($rows)) }} more (of {{ number_format($total) }})</button> <span class="ax-faint ax-text-xs">or find a product above</span></div>
                @endif
            </x-ui.card>
        </div>

        <div class="ax-stack">
            @if($product)
                @php $pt = $product['transfer']; @endphp
                <x-ui.card :title="$product['name']">
                    <div class="ax-between ax-wrap ax-mb-2">
                        <span class="ax-faint ax-text-xs">{{ collect([$product['facts']['subcategory'], $product['facts']['brand'], $product['facts']['pack_size']])->filter()->implode(' · ') }}{{ $product['facts']['price'] > 0 ? ' · ' . $money($product['facts']['price']) : '' }}</span>
                        <button type="button" class="ax-btn" wire:click="focusOn(null)" aria-label="Close the product">Close</button>
                    </div>
                    <div class="ax-stack ax-text-sm">
                        @if($product['on_today'])
                            <p class="ax-m-0"><strong>Sells {{ $money($product['sales']) }} a year here</strong> <x-ui.badge color="observed">Observed</x-ui.badge>
                                — {{ $share($product['share_of_category']) }} of the category, margin {{ $share($product['margin_rate']) }}{{ $product['stock'] ? ', stock ' . $money($product['stock']) . ' at cost' : '' }}.</p>
                        @elseif($product['engine'])
                            <p class="ax-m-0"><strong>Would sell about {{ $money($product['engine']['gross']) }} a year here</strong> <x-ui.badge color="info">Estimated</x-ui.badge> before what it takes from the shelf.</p>
                        @elseif($product['offered'])
                            <p class="ax-m-0"><strong>Would sell about {{ $money($product['offered']['gross']) }} a year here</strong> <x-ui.badge color="accent">Simulated</x-ui.badge> from its share of the category at similar stores — the engine did not propose it.</p>
                        @endif

                        <div>
                            <div class="ax-fw-600 ax-ink">{{ $product['on_today'] ? 'Why keep it, or take it off' : 'Why add it' }}</div>
                            @if($product['engine'])
                                <p class="ax-m-0 ax-muted">{{ $product['engine']['headline'] }} ({{ ucfirst($product['engine']['tier']) }})</p>
                                @foreach(array_slice($product['engine']['explanation'], 0, 4) as $line)
                                    <p class="ax-m-0 ax-faint ax-text-xs">{{ $line }}</p>
                                @endforeach
                                @if($decisionUrl($product['engine']['gap_id']))<a class="ax-text-xs" href="{{ $decisionUrl($product['engine']['gap_id']) }}" wire:navigate>The decision in full</a>@endif
                            @elseif($product['protected'])
                                <p class="ax-m-0 ax-muted">{{ $product['protected'] }}.</p>
                            @else
                                <p class="ax-m-0 ax-muted">No rule flags it: {{ $product['on_today'] ? 'it sells in line with similar stores or is not judged yet' : 'fewer similar stores carry it, or it sells below the bar for a proposal' }}.</p>
                            @endif
                        </div>

                        <div>
                            <div class="ax-fw-600 ax-ink">{{ $product['on_today'] ? 'If it goes' : 'Where its sales would come from' }}</div>
                            @if($pt['mid'] === null)
                                <p class="ax-m-0 ax-muted">{{ $pt['note'] }}</p>
                            @else
                                <p class="ax-m-0 ax-muted">
                                    {{ $product['on_today'] ? 'About ' . $share($pt['mid']) . ' of its buyers would buy something else on the shelf (' . $share($pt['low']) . '–' . $share($pt['high']) . ')' : 'About ' . $share($pt['mid']) . ' of its sales would come from products already on the shelf (' . $share($pt['low']) . '–' . $share($pt['high']) . ')' }}
                                    @if($product['on_today'] && $product['sales']) — about {{ $money($product['sales'] * (1 - $pt['mid'])) }} a year lost to the category @endif.
                                    <x-ui.badge :color="$pt['basis'] === 'observed' ? 'observed' : null">{{ $basisLabel[$pt['basis']] ?? $pt['basis'] }}</x-ui.badge>
                                </p>
                                @if($pt['pairs'])
                                    <p class="ax-m-0 ax-faint ax-text-xs">Who substitutes it: {{ collect($pt['pairs'])->take(4)->map(fn ($p) => $p['name'] . ' ' . $share($p['mid']))->implode(' · ') }}</p>
                                @elseif($pt['note'])
                                    <p class="ax-m-0 ax-faint ax-text-xs">{{ $pt['note'] }}</p>
                                @endif
                            @endif
                        </div>

                        <div>
                            <div class="ax-fw-600 ax-ink">Space and similar stores</div>
                            @if(($product['space']['basis'] ?? 'count') !== 'count' && ($product['space']['metres'] ?? 0) > 0)
                                <p class="ax-m-0 ax-muted">Takes {{ $m2($product['space']['metres']) }}: {{ is_int($product['space']['facings']) || floor($product['space']['facings']) == $product['space']['facings'] ? (int) $product['space']['facings'] : number_format($product['space']['facings'], 1) }} facing(s) × {{ number_format($product['space']['width'] * 100, 0) }} cm{{ $product['space']['width_assumed'] ? ' (width assumed from its subcategory)' : '' }}{{ $product['space']['facings_assumed'] ? ' (facings assumed)' : '' }}.
                                    @if($product['space']['per_metre'] !== null) It earns {{ $money($product['space']['per_metre']) }} a year per metre against the shelf's {{ $money($product['space']['shelf_per_metre']) }}.@endif</p>
                            @else
                                <p class="ax-m-0 ax-muted">One of {{ number_format($product['space']['count']) }} products on the shelf; it earns {{ $money($product['space']['per_slot']) }} a year against an average of {{ $money($product['space']['average']) }} per product.</p>
                            @endif
                            @if($product['peers'])
                                <p class="ax-m-0 ax-muted">Carried by {{ $product['peers']['carrying'] }} of {{ $product['peers']['size'] }} stores in its peer group{{ $product['peers']['sales_a_year'] ? ', typically ' . $money($product['peers']['sales_a_year']) . ' a year per store' : '' }}.</p>
                            @else
                                <p class="ax-m-0 ax-muted">Not carried by any similar store.</p>
                            @endif
                        </div>

                        <div>
                            <div class="ax-fw-600 ax-ink">After similar decisions</div>
                            @php $pp = $product['past']; $n = $pp['success'] + $pp['partial'] + $pp['failure']; @endphp
                            @if($n === 0)
                                <p class="ax-m-0 ax-muted">No {{ $pp['type'] === 'delist' ? 'delist' : 'add' }} in {{ $ctx['category'] }} has been measured yet.</p>
                            @else
                                <p class="ax-m-0 ax-muted">{{ $n }} measured {{ $pp['type'] === 'delist' ? 'delist(s)' : 'add(s)' }} in {{ $ctx['category'] }}: {{ $pp['success'] }} worked, {{ $pp['partial'] }} partly, {{ $pp['failure'] }} did not. <x-ui.badge color="success">Measured</x-ui.badge></p>
                            @endif
                        </div>

                        <div class="ax-row ax-wrap" style="gap:.35rem">
                            @if($product['on_today'] && $product['on_now'] && ! $product['protected'])
                                <button type="button" class="ax-btn" wire:click="remove({{ \Illuminate\Support\Js::from($product['sku']) }})">Take off the range</button>
                            @elseif($product['on_today'] && ! $product['on_now'])
                                <button type="button" class="ax-btn" wire:click="add({{ \Illuminate\Support\Js::from($product['sku']) }})">Put it back</button>
                            @elseif(! $product['on_today'] && ! $product['on_now'] && ($product['engine'] || $product['offered']))
                                <button type="button" class="ax-btn ax-btn--success" wire:click="add({{ \Illuminate\Support\Js::from($product['sku']) }})">Add to the range</button>
                            @elseif(! $product['on_today'] && $product['on_now'])
                                <button type="button" class="ax-btn" wire:click="remove({{ \Illuminate\Support\Js::from($product['sku']) }})">Leave it out again</button>
                            @endif
                        </div>
                    </div>
                </x-ui.card>
            @endif

            <x-ui.card title="Problems and opportunities">
                <div class="ax-stack ax-text-sm">
                    <div class="ax-fw-600 ax-ink">Proposed by the engine <x-ui.badge color="info">Estimated</x-ui.badge></div>
                    @forelse($opportunities['engine'] as $o)
                        <div class="ax-between" wire:key="op-{{ md5($o['sku']) }}">
                            <div><button type="button" class="ax-ink" wire:click="focusOn(@js($o['sku']))">{{ $o['name'] }}</button>
                                <div class="ax-faint ax-text-xs">{{ $money($o['gross_sales']) }} a year · {{ $o['taken']['mid'] === null ? 'where its sales come from is not known' : 'about ' . $share($o['taken']['mid']) . ' from the shelf' }} · {{ ucfirst($o['tier']) }}</div></div>
                            <button type="button" class="ax-btn ax-btn--success" wire:click="add({{ \Illuminate\Support\Js::from($o['sku']) }})">Add</button>
                        </div>
                    @empty
                        <p class="ax-m-0 ax-muted">No adds proposed for this shelf{{ collect($ctx['engine'])->where('kind', 'delist')->count() ? '; delists are marked in the range.' : '.' }}</p>
                    @endforelse

                    @if($opportunities['recover'])
                        <div class="ax-fw-600 ax-ink ax-mt-2">Keeps running out</div>
                        @foreach($opportunities['recover'] as $o)
                            <div class="ax-between" wire:key="rc-{{ md5($o['sku']) }}">
                                <div><button type="button" class="ax-ink" wire:click="focusOn(@js($o['sku']))">{{ $o['name'] }}</button>
                                    <div class="ax-faint ax-text-xs">{{ $money($o['gross_sales']) }} a year of sales when in stock · fixed, never delisted</div></div>
                                <button type="button" class="ax-btn" wire:click="toggleSkip({{ \Illuminate\Support\Js::from($o['sku']) }})">{{ $o['skipped'] ? 'Include' : 'Leave out' }}</button>
                            </div>
                        @endforeach
                    @endif

                    @if($opportunities['others'])
                        <div class="ax-fw-600 ax-ink ax-mt-2">Also carried by similar stores <x-ui.badge color="accent">Simulated</x-ui.badge></div>
                        <p class="ax-m-0 ax-faint ax-text-xs">Not proposed by the engine (fewer stores carry them, or they sell below the bar), but sold by at least {{ \App\Services\Assortment\StudioService::MIN_PEERS_OFFERED }} similar stores. Adding one is your change, valued at low confidence.</p>
                        @foreach(array_slice($opportunities['others'], 0, 12) as $o)
                            <div class="ax-between" wire:key="ot-{{ md5($o['sku']) }}">
                                <div><button type="button" class="ax-ink" wire:click="focusOn(@js($o['sku']))">{{ $o['name'] }}</button>
                                    <div class="ax-faint ax-text-xs">{{ $money($o['gross_sales']) }} a year · {{ $o['taken']['mid'] === null ? 'source unknown' : 'about ' . $share($o['taken']['mid']) . ' from the shelf' }}</div></div>
                                <button type="button" class="ax-btn" wire:click="add({{ \Illuminate\Support\Js::from($o['sku']) }})">Add</button>
                            </div>
                        @endforeach
                    @endif
                </div>
            </x-ui.card>

            <p class="ax-faint ax-text-xs">Labels: <x-ui.badge color="observed">Observed</x-ui.badge> from the store's history · <x-ui.badge color="info">Estimated</x-ui.badge> by the engine's rules · <x-ui.badge color="accent">Simulated</x-ui.badge> what a change would do, a year · <x-ui.badge color="success">Measured</x-ui.badge> after a change. Autnyx never changes the range itself: make the plan, then carry it out in your own merchandising system.</p>
        </div>
    </div>
@endif
</div>
@endif
</x-filament-panels::page>
