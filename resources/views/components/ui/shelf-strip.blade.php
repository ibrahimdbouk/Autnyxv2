@props([
    'strip',                       // App\Services\Assortment\ShelfStrip::build()
    'todayLabel' => 'Today',
    'planLabel'  => 'Plan',
])
@php
    $W = 1000;
    $k = $W / max(0.0001, (float) $strip['scale']);
    $id = 'strip-' . substr(md5(json_encode([$strip['today'], $strip['plan']])), 0, 8);
    $m2 = static fn ($v) => number_format((float) $v, 2) . ' m';
    $short = static function (string $name, float $w): ?string {
        $n = (int) floor(($w - 8) / 6.2);
        if ($n < 3) {
            return null;
        }

        return mb_strlen($name) > $n ? rtrim(mb_substr($name, 0, max(1, $n - 1))) . '…' : $name;
    };
    $groups = static function (array $blocks): array {
        $out = [];
        foreach ($blocks as $b) {
            $last = count($out) - 1;
            if ($last >= 0 && $out[$last]['sub'] === $b['sub']) {
                $out[$last]['to'] = $b['x'] + $b['metres'];
            } else {
                $out[] = ['sub' => $b['sub'], 'from' => $b['x'], 'to' => $b['x'] + $b['metres']];
            }
        }

        return $out;
    };
    $rows = [
        ['label' => $todayLabel, 'blocks' => $strip['today'], 'used' => $strip['used_today'], 'y' => 22, 'plan' => false],
        ['label' => $planLabel, 'blocks' => $strip['plan'], 'used' => $strip['used_plan'], 'y' => 128, 'plan' => true],
    ];
    $what = [
        'keep' => 'stays', 'add' => 'added', 'delist' => 'goes', 'cut' => 'fewer facings', 'grow' => 'more facings',
    ];
    $c = $strip['counts'];
@endphp
<div {{ $attributes->merge(['class' => 'ax-stack']) }}>
    <div class="ax-scroll-x">
    <svg class="ax-strip" viewBox="0 0 {{ $W }} 222" role="img"
        aria-label="Shelf strip: {{ $todayLabel }} uses {{ $m2($strip['used_today']) }}, {{ $planLabel }} uses {{ $m2($strip['used_plan']) }} of {{ $m2($strip['metres']) }}; {{ $c['add'] }} added, {{ $c['delist'] }} going, {{ $c['cut'] }} with fewer facings">
        <defs>
            <pattern id="{{ $id }}-hatch" width="8" height="8" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
                <line class="ax-strip-hatch" x1="0" y1="0" x2="0" y2="8" />
            </pattern>
        </defs>
        @foreach($rows as $row)
            @php $y = $row['y']; $shelfW = $strip['metres'] * $k; @endphp
            <text class="ax-strip-label" x="0" y="{{ $y - 8 }}">{{ $row['label'] }} · {{ $m2($row['used']) }} of {{ $m2($strip['metres']) }}{{ $row['used'] > $strip['metres'] + 0.005 ? ' · over by ' . $m2($row['used'] - $strip['metres']) : '' }}</text>
            @if($row['used'] > $strip['metres'] + 0.005)
                {{-- Behind the blocks: a band past the shelf's end shows what does not fit. --}}
                <rect class="ax-strip-over" x="{{ $shelfW }}" y="{{ $y - 6 }}" width="{{ ($row['used'] - $strip['metres']) * $k }}" height="60">
                    <title>Over the shelf by {{ $m2($row['used'] - $strip['metres']) }}</title>
                </rect>
            @endif
            @foreach($row['blocks'] as $b)
                @php $x = $b['x'] * $k; $w = max(1.0, $b['metres'] * $k); $label = $short($b['name'], $w); @endphp
                <g>
                    <title>{{ $b['name'] }} — {{ $what[$b['state']] }}{{ $b['facings'] !== null ? ' · ' . $b['facings'] . ' facing(s)' . ($b['facings_today'] !== null && $b['facings_today'] != $b['facings'] ? ' (today ' . $b['facings_today'] . ')' : '') : '' }} · {{ $m2($b['metres']) }}</title>
                    <rect class="ax-strip-block ax-strip-block--{{ $b['state'] }}" x="{{ $x }}" y="{{ $y }}" width="{{ $w }}" height="48" rx="2" />
                    @if($b['state'] === 'delist')
                        <rect x="{{ $x }}" y="{{ $y }}" width="{{ $w }}" height="48" fill="url(#{{ $id }}-hatch)" />
                    @endif
                    @if($label)
                        <text class="ax-strip-name" x="{{ $x + 4 }}" y="{{ $y + 28 }}">{{ $label }}</text>
                    @endif
                </g>
            @endforeach
            @if($row['plan'] && $row['used'] < $strip['metres'] - 0.005)
                <rect class="ax-strip-free" x="{{ $row['used'] * $k }}" y="{{ $y }}" width="{{ ($strip['metres'] - $row['used']) * $k }}" height="48" rx="2">
                    <title>Free: {{ $m2($strip['metres'] - $row['used']) }}</title>
                </rect>
            @endif
            <rect class="ax-strip-shelf" x="0" y="{{ $y - 2 }}" width="{{ $shelfW }}" height="52" />
            @if($row['used'] > $strip['metres'] + 0.005)
                <line class="ax-strip-end" x1="{{ $shelfW }}" y1="{{ $y - 8 }}" x2="{{ $shelfW }}" y2="{{ $y + 56 }}" />
            @endif
            @foreach($groups($row['blocks']) as $g)
                @if(($g['to'] - $g['from']) * $k >= 60)
                    <text class="ax-strip-sub" x="{{ $g['from'] * $k + 2 }}" y="{{ $y + 66 }}">{{ $short($g['sub'], ($g['to'] - $g['from']) * $k) }}</text>
                @endif
            @endforeach
        @endforeach
    </svg>
    </div>
    <div class="ax-row ax-wrap ax-gap-3 ax-text-xs ax-muted">
        <span><span class="ax-strip-key"></span> Stays</span>
        <span><span class="ax-strip-key ax-strip-key--add"></span> Added ({{ $c['add'] }})</span>
        <span><span class="ax-strip-key ax-strip-key--delist"></span> Goes ({{ $c['delist'] }})</span>
        <span><span class="ax-strip-key ax-strip-key--cut"></span> Fewer facings ({{ $c['cut'] }})</span>
        @if($c['grow'])<span><span class="ax-strip-key ax-strip-key--grow"></span> More facings ({{ $c['grow'] }})</span>@endif
        <span><span class="ax-strip-key ax-strip-key--free"></span> Free space</span>
        @if($strip['used_today'] > $strip['metres'] + 0.005 || $strip['used_plan'] > $strip['metres'] + 0.005)<span><span class="ax-strip-key ax-strip-key--over"></span> Past the shelf's end</span>@endif
    </div>
    <p class="ax-faint ax-text-xs ax-m-0">Schematic, not a planogram: each product is as wide as its facings × width, grouped by subcategory and ordered by sales — not where it sits on the shelf. Hover a block for its facings.{{ $strip['note'] ? ' ' . $strip['note'] : '' }}</p>
</div>
