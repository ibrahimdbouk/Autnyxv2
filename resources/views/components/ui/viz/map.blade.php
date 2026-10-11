@props([
    'points'    => [],   // list of ['lat' => , 'lng' => , 's' => series 1–8, 'r' => radius, 'tip' => , 'approx' => bool, 'dim' => bool]
    'series'    => [],   // series number => label (the legend)
    'landmarks' => [],   // list of ['lat' => , 'lng' => , 'label' => ] (cities, drawn faint)
    'width'     => 760,
    'height'    => 420,
    'label'     => 'Map',
    'street'    => null,  // offer the street map (default: config autnyx.maps.enabled)
])
@php
    // A schematic map: positions to scale (longitude shrunk by the latitude's cosine), no base map
    // (no tiles leave the app, and no store location leaves it either).
    $W = max(320, (int) $width); $H = max(220, (int) $height); $pad = 28;
    $all = array_merge($points, $landmarks);
    $lats = array_map(fn ($p) => (float) $p['lat'], $all ?: [['lat' => 0]]);
    $lngs = array_map(fn ($p) => (float) $p['lng'], $all ?: [['lng' => 0]]);
    $la0 = min($lats); $la1 = max($lats); $lo0 = min($lngs); $lo1 = max($lngs);
    // At least a few kilometres across, so one city does not fill the frame.
    $minSpan = 0.08;
    if ($la1 - $la0 < $minSpan) { $c = ($la0 + $la1) / 2; $la0 = $c - $minSpan / 2; $la1 = $c + $minSpan / 2; }
    if ($lo1 - $lo0 < $minSpan) { $c = ($lo0 + $lo1) / 2; $lo0 = $c - $minSpan / 2; $lo1 = $c + $minSpan / 2; }
    $k = cos(deg2rad(($la0 + $la1) / 2));
    $u = min(($W - 2 * $pad) / max(1e-6, ($lo1 - $lo0) * $k), ($H - 2 * $pad) / max(1e-6, $la1 - $la0)) * 0.9;
    $cLo = ($lo0 + $lo1) / 2; $cLa = ($la0 + $la1) / 2;
    $px = fn ($lng) => round($W / 2 + ((float) $lng - $cLo) * $k * $u, 2);
    $py = fn ($lat) => round($H / 2 - ((float) $lat - $cLa) * $u, 2);
    // Graticule every round step, about five lines across the wider side.
    $span = max(($lo1 - $lo0), ($la1 - $la0)) / 0.9;
    $step = 0.01;
    foreach ([0.01, 0.02, 0.05, 0.1, 0.2, 0.25, 0.5, 1, 2, 5, 10] as $s) { if ($span / $s <= 6) { $step = $s; break; } }
    $latMin = $cLa - ($H / 2) / $u; $latMax = $cLa + ($H / 2) / $u;
    $lngMin = $cLo - ($W / 2) / ($k * $u); $lngMax = $cLo + ($W / 2) / ($k * $u);
    $deg = fn ($v, $pos, $neg) => rtrim(rtrim(number_format(abs($v), $step < 0.1 ? 2 : ($step < 1 ? 1 : 0)), '0'), '.') . '°' . ($v >= 0 ? $pos : $neg);
@endphp
@php
    $street ??= (bool) config('autnyx.maps.enabled', false);
    if ($street) {
        // The street map: the same stores, on map tiles (self-hosted Leaflet). Street View and
        // Google Maps links only for stores at their own coordinates — a city-placed dot is not a place.
        $mapCfg = [
            'tiles' => (string) config('autnyx.maps.tiles'), 'attribution' => (string) config('autnyx.maps.attribution'),
            'maxZoom' => (int) config('autnyx.maps.max_zoom', 19),
            'points' => array_values(array_map(function ($p) {
                $exact = empty($p['approx']);
                $ll = round((float) $p['lat'], 6) . ',' . round((float) $p['lng'], 6);

                return ['lat' => round((float) $p['lat'], 6), 'lng' => round((float) $p['lng'], 6), 'r' => (float) ($p['r'] ?? 6),
                    'cls' => ! empty($p['dim']) ? 'ax-viz-base' : \App\Support\Viz::series((int) ($p['s'] ?? 1)),
                    'dim' => ! empty($p['dim']), 'approx' => ! $exact, 'tip' => (string) ($p['tip'] ?? ''),
                    'links' => $exact ? [
                        ['label' => 'Street View', 'url' => 'https://www.google.com/maps/@?api=1&map_action=pano&viewpoint=' . $ll],
                        ['label' => 'Google Maps', 'url' => 'https://www.google.com/maps/search/?api=1&query=' . $ll],
                    ] : null];
            }, $points)),
        ];
    }
@endphp
@if($street)
<div x-data="{ view: $persist('street').as('ax-map-view') }" x-effect="view === 'street' && $nextTick(() => window.dispatchEvent(new CustomEvent('ax-map-shown')))">
    <div class="ax-row ax-gap-1 ax-mb-2" role="tablist" aria-label="Map view">
        <button type="button" class="ax-btn" x-bind:class="view === 'street' && 'ax-btn--on'" x-on:click="view = 'street'" role="tab" x-bind:aria-selected="view === 'street'">Street map</button>
        <button type="button" class="ax-btn" x-bind:class="view === 'schematic' && 'ax-btn--on'" x-on:click="view = 'schematic'" role="tab" x-bind:aria-selected="view === 'schematic'">Schematic</button>
    </div>
    <div x-show="view === 'street'" wire:ignore>
        <div class="ax-leaflet" data-ax-map="{{ json_encode($mapCfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}" role="img" aria-label="{{ $label }} — street map"></div>
        <p class="ax-faint ax-text-xs ax-mt-1 ax-m-0">Click a store for its figures and, where it has coordinates, Street View. Map tiles come from the map provider; store names and figures stay in Autnyx.</p>
    </div>
    <div x-show="view === 'schematic'" x-cloak>
<div class="ax-scroll-x">
<svg class="ax-viz ax-viz--wide" width="{{ $W }}" height="{{ $H }}" viewBox="0 0 {{ $W }} {{ $H }}" role="img" aria-label="{{ $label }}">
    <rect class="ax-viz-map" x="0" y="0" width="{{ $W }}" height="{{ $H }}" rx="10" />
    @for($g = ceil($lngMin / $step) * $step; $g <= $lngMax; $g += $step)
        <line class="ax-viz-graticule" x1="{{ $px($g) }}" x2="{{ $px($g) }}" y1="0" y2="{{ $H }}" />
        <text class="ax-viz-text--faint ax-viz-text" x="{{ $px($g) + 3 }}" y="{{ $H - 6 }}">{{ $deg($g, 'E', 'W') }}</text>
    @endfor
    @for($g = ceil($latMin / $step) * $step; $g <= $latMax; $g += $step)
        <line class="ax-viz-graticule" x1="0" x2="{{ $W }}" y1="{{ $py($g) }}" y2="{{ $py($g) }}" />
        <text class="ax-viz-text--faint ax-viz-text" x="6" y="{{ $py($g) - 3 }}">{{ $deg($g, 'N', 'S') }}</text>
    @endfor
    @foreach($landmarks as $m)
        <g>
            <circle class="ax-viz-landmark" cx="{{ $px($m['lng']) }}" cy="{{ $py($m['lat']) }}" r="2.5" />
            <text class="ax-viz-text ax-viz-text--landmark" x="{{ $px($m['lng']) + 6 }}" y="{{ $py($m['lat']) - 6 }}">{{ $m['label'] }}</text>
        </g>
    @endforeach
    {{-- Dimmed stores first, so the ones in focus sit on top. --}}
    @foreach(collect($points)->sortBy(fn ($p) => empty($p['dim']) ? 1 : 0) as $p)
        <circle @class(['ax-viz-dot ax-viz-hit', \App\Support\Viz::series((int) ($p['s'] ?? 1)) => empty($p['dim']), 'ax-viz-base' => ! empty($p['dim']), 'ax-viz-dot--approx' => ! empty($p['approx'])])
            cx="{{ $px($p['lng']) }}" cy="{{ $py($p['lat']) }}" r="{{ $p['r'] ?? 6 }}" fill-opacity="{{ ! empty($p['dim']) ? '0.25' : (! empty($p['approx']) ? '0.45' : '0.85') }}"><title>{{ $p['tip'] ?? '' }}</title></circle>
    @endforeach
</svg>
</div>
    </div>
</div>
@else
<div class="ax-scroll-x">
<svg class="ax-viz ax-viz--wide" width="{{ $W }}" height="{{ $H }}" viewBox="0 0 {{ $W }} {{ $H }}" role="img" aria-label="{{ $label }}">
    <rect class="ax-viz-map" x="0" y="0" width="{{ $W }}" height="{{ $H }}" rx="10" />
    @for($g = ceil($lngMin / $step) * $step; $g <= $lngMax; $g += $step)
        <line class="ax-viz-graticule" x1="{{ $px($g) }}" x2="{{ $px($g) }}" y1="0" y2="{{ $H }}" />
        <text class="ax-viz-text--faint ax-viz-text" x="{{ $px($g) + 3 }}" y="{{ $H - 6 }}">{{ $deg($g, 'E', 'W') }}</text>
    @endfor
    @for($g = ceil($latMin / $step) * $step; $g <= $latMax; $g += $step)
        <line class="ax-viz-graticule" x1="0" x2="{{ $W }}" y1="{{ $py($g) }}" y2="{{ $py($g) }}" />
        <text class="ax-viz-text--faint ax-viz-text" x="6" y="{{ $py($g) - 3 }}">{{ $deg($g, 'N', 'S') }}</text>
    @endfor
    @foreach($landmarks as $m)
        <g>
            <circle class="ax-viz-landmark" cx="{{ $px($m['lng']) }}" cy="{{ $py($m['lat']) }}" r="2.5" />
            <text class="ax-viz-text ax-viz-text--landmark" x="{{ $px($m['lng']) + 6 }}" y="{{ $py($m['lat']) - 6 }}">{{ $m['label'] }}</text>
        </g>
    @endforeach
    {{-- Dimmed stores first, so the ones in focus sit on top. --}}
    @foreach(collect($points)->sortBy(fn ($p) => empty($p['dim']) ? 1 : 0) as $p)
        <circle @class(['ax-viz-dot ax-viz-hit', \App\Support\Viz::series((int) ($p['s'] ?? 1)) => empty($p['dim']), 'ax-viz-base' => ! empty($p['dim']), 'ax-viz-dot--approx' => ! empty($p['approx'])])
            cx="{{ $px($p['lng']) }}" cy="{{ $py($p['lat']) }}" r="{{ $p['r'] ?? 6 }}" fill-opacity="{{ ! empty($p['dim']) ? '0.25' : (! empty($p['approx']) ? '0.45' : '0.85') }}"><title>{{ $p['tip'] ?? '' }}</title></circle>
    @endforeach
</svg>
</div>
@endif
<div class="ax-viz-legend" aria-hidden="true">
    @foreach($series as $i => $name)
        <span><svg class="ax-viz-key" viewBox="0 0 10 10"><circle class="{{ \App\Support\Viz::series((int) $i) }}" cx="5" cy="5" r="4.5" /></svg>{{ $name }}</span>
    @endforeach
    @if(collect($points)->contains(fn ($p) => ! empty($p['approx'])))
        <span><svg class="ax-viz-key" viewBox="0 0 10 10"><circle class="ax-viz-s1 ax-viz-dot--approx" cx="5" cy="5" r="3.5" fill-opacity="0.45" /></svg>Placed at its city (no coordinates)</span>
    @endif
    {{ $slot }}
</div>
