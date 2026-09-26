{{-- The Root Cause app's dashboard — shown as the Root Cause tab of the one
     Dashboard (App\Filament\Pages\Dashboard). Figures come from
     App\Filament\Dashboards\RootCauseDashboard::data(). --}}
@php
/* WP7.1 — every figure comes from App\Services\Metrics\DashboardMetrics via
   Dashboard::getViewData() ($m, $links, $recentHighPriority, $pendingActions).
   This block only formats. */
$k = $m['kpi'];
$revenueAtRisk      = $k['revenue_at_risk'];
$openCount          = $k['open'];
$highPriorityCount  = $k['high'];
$overdueCount       = $k['overdue'];
$recoveredMTD       = $k['recovered_mtd'];
$observedClearedMTD = $k['cleared_mtd'];
$statusBreakdown    = $m['status'];
$chartLabels        = $m['chart']['labels'];
$chartAtRisk        = $m['chart']['atRisk'];
$chartRecovered     = $m['chart']['recovered'];
$chartObsCleared    = $m['chart']['cleared'];
$topDrivers         = $m['drivers'];
$totalDriverAnomalies = array_sum(array_column($topDrivers, 'cnt')) ?: 1;
$recurringTop       = $m['insights']['recurring'];
$storeAlertData     = $m['insights']['store'];
$categoryTop        = $m['insights']['month_top'];

$dbFormatMoney = static fn (float $val): string => \App\Support\Money::displayCompact($val, $currency);   // cards: the currency sign
$dbTableMoney = static fn (float $val): string => \App\Support\Money::compact($val, $currency);          // tables: the ISO code

// Stock cards trend on their inflow (new this week vs the week before);
// recovery cards on month to date vs the same stretch of last month.
$T = \App\Services\Metrics\DashboardMetrics::class;
$riskTrend = $T::trend($m['flow']['risk'][0], $m['flow']['risk'][1], false);
$openTrend = $T::trend($m['flow']['open'][0], $m['flow']['open'][1], false);
$highTrend = $T::trend($m['flow']['high'][0], $m['flow']['high'][1], false);
$recTrend  = $T::trend($recoveredMTD, $m['prev']['recovered_mtd'], true);
$obsTrend  = $T::trend($observedClearedMTD, $m['prev']['cleared_mtd'], true);
$dbTrendClass = static fn (array $t): string => match ($t['good']) {
    true => 'db-trend-up', false => 'db-trend-down', default => 'db-trend-flat',
};
$dbArrow = static fn (array $t): string => $t['dir'] === 'up' ? '↑' : ($t['dir'] === 'down' ? '↓' : '—');

$dbRuleLabel = static function (string $ruleType): string {
    return \App\Models\AnomalySetting::RULES[$ruleType]['label'] ?? ucwords(str_replace('_', ' ', $ruleType));
};

$dbStatusLabel = static function(string $s): string {
    return match($s) {
        'open'        => 'Open',
        'in_progress' => 'In Progress',
        'resolved'    => 'Resolved',
        'closed'      => 'Closed',
        default       => ucfirst($s),
    };
};

/* ── Sparkline SVG generator ───────────────────────────────────────────── */
$dbSparkline = static function(array $values, string $color = '#6d28d9', int $w = 80, int $h = 28): string {
    if (count($values) < 2) {
        return "<svg width='{$w}' height='{$h}'><line x1='0' y1='" . ($h/2) . "' x2='{$w}' y2='" . ($h/2) . "' stroke='$color' stroke-width='1.5' opacity='.4'/></svg>";
    }
    $min  = min($values);
    $max  = max($values);
    $range = ($max - $min) ?: 1;
    $pad  = 3;
    $pts  = [];
    foreach ($values as $i => $v) {
        $x = $pad + ($i / (count($values)-1)) * ($w - $pad*2);
        $y = $h - $pad - (($v - $min) / $range) * ($h - $pad*2);
        $pts[] = round($x,1).','.round($y,1);
    }
    $poly = implode(' ',$pts);
    $first = $pts[0]; $last = $pts[count($pts)-1];
    [$lx,$ly] = explode(',',$last);
    [$fx,$fy] = explode(',',$first);
    $areaPoly = $poly.' '.$lx.','.($h-$pad).' '.$fx.','.($h-$pad);
    return "<svg width='{$w}' height='{$h}' viewBox='0 0 {$w} {$h}'>
      <polygon points='$areaPoly' fill='$color' opacity='.12'/>
      <polyline points='$poly' fill='none' stroke='$color' stroke-width='1.75' stroke-linecap='round' stroke-linejoin='round'/>
    </svg>";
};

$riskSparkData  = array_slice($chartAtRisk, -7);
$recSparkData   = array_slice($chartRecovered, -7);
$obsSparkData   = array_slice($chartObsCleared, -7);
@endphp

<style>
/* ══════════════════════════════════════════════════════════════════════════
   db-* — Dashboard custom view
   ══════════════════════════════════════════════════════════════════════════ */

/* ── KPI grid ─────────────────────────────────────────────────────────── */
.db-kpi-grid {
    display:grid;
    grid-template-columns:repeat(2, 1fr);
    gap:1rem;
    margin-bottom:1.5rem;
}
@media(min-width:640px)  { .db-kpi-grid { grid-template-columns:repeat(3,1fr); } }
@media(min-width:1280px) { .db-kpi-grid { grid-template-columns:repeat(6,1fr); } }
/* ── Clickable KPI card (anchor) ──────────────────────────────────────── */
a.db-kpi {
    text-decoration:none; color:inherit; position:relative;
    cursor:pointer;
    transition:box-shadow .15s ease, transform .15s ease, border-color .15s ease;
}
a.db-kpi:not([href]) { cursor:default; }
a.db-kpi:not([href]):hover { transform:none; box-shadow:var(--ax-shadow); border-color:var(--ax-line); }
a.db-kpi:not([href])::after { display:none; }
.db-kpi-spark:empty { min-height:28px; }
.db-amber { color:var(--ax-warning, #d97706); }
.db-red   { color:var(--ax-danger, #dc2626); }
.db-green { color:var(--ax-success, #16a34a); }
.db-teal  { color:var(--ax-teal, #0d9488); }
a.db-kpi:hover {
    box-shadow:var(--ax-shadow-md);
    transform:translateY(-2px);
    border-color:var(--ax-accent-300);
}
a.db-kpi::after {
    content:'↗'; position:absolute; top:.7rem; right:.85rem;
    font-size:.8rem; color:var(--ax-accent-300); opacity:0; transition:opacity .15s ease;
}
a.db-kpi:hover::after { opacity:1; }

/* ── KPI card ─────────────────────────────────────────────────────────── */
.db-kpi {
    background:var(--ax-bg);
    border:1px solid var(--ax-line);
    border-radius:.875rem;
    padding:1rem 1.125rem .875rem;
    box-shadow:var(--ax-shadow);
    display:flex; flex-direction:column; gap:.375rem;
    overflow:hidden;
}
.db-kpi-label {
    font-size:.6875rem; font-weight:700;
    text-transform:uppercase; letter-spacing:.055em; color:var(--ax-faint);
}
.db-kpi-value {
    font-size:1.5rem; font-weight:800; color:var(--ax-ink); line-height:1.1;
}
.db-kpi-trend {
    display:flex; align-items:center; gap:.25rem;
    font-size:.75rem; font-weight:600;
}
.db-trend-up   { color:var(--ax-success); }
.db-trend-down { color:var(--ax-danger); }
.db-trend-flat { color:var(--ax-faint); }
.db-kpi-spark  { margin-top:.25rem; line-height:0; }

/* ── Charts section: 3-col ─────────────────────────────────────────────── */
.db-charts-grid {
    display:grid;
    grid-template-columns:1fr;
    gap:1.25rem;
    margin-bottom:1.25rem;
}
@media(min-width:768px)  { .db-charts-grid { grid-template-columns:1fr 1fr; } }
@media(min-width:1200px) { .db-charts-grid { grid-template-columns:2fr 1fr 1fr; } }

/* ── Generic card ─────────────────────────────────────────────────────── */
.db-card {
    background:var(--ax-bg);
    border:1px solid var(--ax-line);
    border-radius:.875rem;
    box-shadow:var(--ax-shadow);
    overflow:hidden;
    display:flex; flex-direction:column;
}
.db-card-head {
    display:flex; align-items:center; gap:.5rem;
    padding:.7rem 1.25rem;
    border-bottom:1px solid var(--ax-line);
    background:var(--ax-panel);
    font-size:.775rem; font-weight:700;
    text-transform:uppercase; letter-spacing:.05em; color:var(--ax-text);
}
.db-card-body { padding:1rem 1.25rem; flex:1; color:var(--ax-text); }

/* ── Canvas wrappers ──────────────────────────────────────────────────── */
.db-chart-wrap { position:relative; width:100%; }
.db-chart-wrap-lg  { height:200px; }
.db-chart-wrap-sm  { height:180px; }

/* ── Top drivers table ─────────────────────────────────────────────────── */
.db-driver-table { width:100%; border-collapse:collapse; }
.db-driver-table td { padding:.45rem .25rem; font-size:.8rem; color:var(--ax-text); vertical-align:middle; }
.db-driver-table tr:last-child td { border-bottom:none; }
.db-driver-table tr.db-driver-link { cursor:pointer; transition:background .12s ease; }
.db-driver-table tr.db-driver-link:hover td { background:var(--ax-accent-soft); }
.db-driver-name  { font-weight:600; color:var(--ax-ink); white-space:nowrap; }
.db-driver-bar-wrap { width:100%; background:var(--ax-neutral-soft); border-radius:9999px; height:.45rem; overflow:hidden; }
.db-driver-bar   { height:100%; border-radius:9999px; background:linear-gradient(90deg,var(--ax-accent-strong),var(--ax-accent-500)); }
.db-driver-pct   { text-align:right; font-weight:700; color:var(--ax-accent-strong); white-space:nowrap; padding-left:.5rem; }

/* ── Bottom 2-col ──────────────────────────────────────────────────────── */
.db-bottom-grid {
    display:grid;
    grid-template-columns:1fr;
    gap:1.25rem;
    margin-bottom:1.25rem;
}
@media(min-width:768px) { .db-bottom-grid { grid-template-columns:1fr 1fr; } }

/* ── Investigations table ──────────────────────────────────────────────── */
.db-inv-table { width:100%; border-collapse:collapse; font-size:.8rem; }
.db-inv-table th {
    padding:.5rem .875rem; font-size:.7rem; font-weight:700;
    text-transform:uppercase; letter-spacing:.05em; color:var(--ax-faint);
    border-bottom:1px solid var(--ax-line); background:var(--ax-panel); text-align:left;
}
.db-inv-table td { padding:.6rem .875rem; border-bottom:1px solid var(--ax-line-2); color:var(--ax-text); vertical-align:middle; }
.db-inv-table tr:last-child td { border-bottom:none; }
.db-inv-table tr:hover td { background:var(--ax-accent-soft); cursor:pointer; }
.db-inv-id { font-family:monospace; font-size:.75rem; color:var(--ax-accent-strong); font-weight:700; }
.db-inv-sub { font-size:.725rem; color:var(--ax-faint); margin-top:.1rem; }

/* ── Priority dot ──────────────────────────────────────────────────────── */
.db-dot {
    display:inline-block; width:.55rem; height:.55rem;
    border-radius:50%; flex-shrink:0;
    margin-right:.375rem;
}
.db-dot-critical { background:var(--ax-danger); }
.db-dot-high     { background:var(--ax-warning); }
.db-dot-medium   { background:var(--ax-info); }
.db-dot-low      { background:#d1d5db; }

/* ── Badges ─────────────────────────────────────────────────────────────── */
.db-badge {
    display:inline-flex; align-items:center;
    padding:.15rem .55rem;
    border-radius:9999px;
    font-size:.7rem; font-weight:600; white-space:nowrap;
}
.db-badge-danger   { background:var(--ax-danger-soft); color:var(--ax-danger-fg); }
.db-badge-warning  { background:var(--ax-warning-soft); color:var(--ax-warning-fg); }
.db-badge-info     { background:var(--ax-info-soft); color:var(--ax-info-fg); }
.db-badge-success  { background:var(--ax-success-soft); color:var(--ax-success-fg); }
.db-badge-gray     { background:var(--ax-neutral-soft); color:var(--ax-neutral-fg); }

/* ── Action center list ────────────────────────────────────────────────── */
.db-action-item {
    display:flex; align-items:flex-start; gap:.75rem;
    padding:.7rem 1.25rem; border-bottom:1px solid var(--ax-line-2);
}
.db-action-item:last-child { border-bottom:none; }
.db-action-meta  { flex:1; min-width:0; }
.db-action-title { font-size:.8125rem; font-weight:600; color:var(--ax-ink); }
.db-action-sub   { font-size:.75rem; color:var(--ax-faint); margin-top:.125rem; }
.db-action-btns  { display:flex; gap:.375rem; flex-shrink:0; align-items:center; }
.db-btn-sm {
    padding:.2rem .65rem;
    border-radius:.375rem;
    font-size:.7rem; font-weight:600;
    cursor:pointer; text-decoration:none;
    border:1px solid;
    display:inline-block;
    white-space:nowrap;
}
.db-btn-outline-purple { color:var(--ax-accent-strong); border-color:var(--ax-accent-300); background:transparent; }
.db-btn-outline-purple:hover { background:var(--ax-accent-soft); }
.db-btn-solid-purple { color:var(--ax-accent-fg); background:var(--ax-accent-strong); border-color:var(--ax-accent-strong); }
.db-btn-solid-purple:hover { background:var(--ax-accent-800); }

/* ── SLA indicator ─────────────────────────────────────────────────────── */
.db-sla { font-size:.7rem; font-weight:700; }
.db-sla-overdue { color:var(--ax-danger); }
.db-sla-ok      { color:var(--ax-faint); }

/* ── Insights carousel ─────────────────────────────────────────────────── */
.db-insights { margin-bottom:1.25rem; }
.db-insights-title {
    font-size:.775rem; font-weight:700; text-transform:uppercase;
    letter-spacing:.05em; color:var(--ax-text); margin-bottom:.875rem;
    display:flex; align-items:center; gap:.5rem;
}
.db-carousel-wrap { overflow-x:auto; scroll-snap-type:x mandatory; -webkit-overflow-scrolling:touch; padding-bottom:.5rem; }
.db-carousel { display:flex; gap:1rem; }
.db-insight-card {
    flex:0 0 260px; scroll-snap-align:start;
    background:var(--ax-bg); border:1px solid var(--ax-line);
    border-radius:.875rem; box-shadow:var(--ax-shadow);
    overflow:hidden;
}
.db-insight-top   { height:.275rem; }
.db-insight-top-red    { background:var(--ax-danger); }
.db-insight-top-orange { background:var(--ax-warning); }
.db-insight-top-green  { background:var(--ax-success); }
.db-insight-body  { padding:1rem; }
.db-insight-icon  { font-size:1.25rem; margin-bottom:.5rem; line-height:1; }
.db-insight-label { font-size:.65rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--ax-faint); margin-bottom:.25rem; }
.db-insight-title { font-size:.875rem; font-weight:700; color:var(--ax-ink); margin-bottom:.375rem; line-height:1.3; }
.db-insight-desc  { font-size:.775rem; color:var(--ax-muted); line-height:1.55; margin-bottom:.75rem; }
.db-insight-link  { font-size:.775rem; font-weight:600; color:var(--ax-accent-strong); text-decoration:none; }
.db-insight-link:hover { text-decoration:underline; }

/* ── Empty states ──────────────────────────────────────────────────────── */
.db-empty { padding:1.75rem 1.25rem; text-align:center; font-size:.8125rem; color:var(--ax-faint); }
</style>

{{-- ════════════════════════════════════════════════════════════════════════
     BRIEFINGS (daily left · weekly right) + DATA-FEED HEALTH — top of dashboard
     ════════════════════════════════════════════════════════════════════════ --}}
@include('filament.partials.dashboard-briefings')
@include('filament.partials.dashboard-feed-health')
@include('filament.partials.dashboard-ops-pulse')

{{-- ════════════════════════════════════════════════════════════════════════
     KPI CARDS
     ════════════════════════════════════════════════════════════════════════ --}}
<div class="db-kpi-grid">

    {{-- Revenue at Risk: open now; trend = new at risk this week vs the week before --}}
    <a @if($links['revenue_at_risk']) href="{{ $links['revenue_at_risk'] }}" @endif class="db-kpi" title="Revenue at risk on open investigations">
        <div class="db-kpi-label">Revenue at Risk</div>
        <div class="db-kpi-value">{{ $dbFormatMoney((float)$revenueAtRisk) }}</div>
        <div class="db-kpi-trend {{ $dbTrendClass($riskTrend) }}" title="New at risk in the last 7 days against the 7 days before">
            {{ $dbArrow($riskTrend) }}
            @if($riskTrend['pct'] !== null) {{ $riskTrend['pct'] }}% new vs last week @else {{ $dbFormatMoney($m['flow']['risk'][0]) }} new this week @endif
        </div>
        <div class="db-kpi-spark">{!! $dbSparkline($riskSparkData, '#dc2626') !!}</div>
    </a>

    {{-- Open Investigations: trend = opened this week vs the week before --}}
    <a @if($links['open']) href="{{ $links['open'] }}" @endif class="db-kpi" title="Open and in-progress investigations">
        <div class="db-kpi-label">Open Investigations</div>
        <div class="db-kpi-value">{{ number_format($openCount) }}</div>
        <div class="db-kpi-trend {{ $dbTrendClass($openTrend) }}" title="Opened in the last 7 days against the 7 days before">
            {{ $dbArrow($openTrend) }}
            @if($openTrend['pct'] !== null) {{ $openTrend['pct'] }}% opened vs last week @else {{ $m['flow']['open'][0] }} opened this week @endif
        </div>
        <div class="db-kpi-spark"></div>
    </a>

    {{-- High Priority --}}
    <a @if($links['high']) href="{{ $links['high'] }}" @endif class="db-kpi" title="Open high and critical priority investigations">
        <div class="db-kpi-label">High Priority</div>
        <div class="db-kpi-value db-amber">{{ number_format($highPriorityCount) }}</div>
        <div class="db-kpi-trend {{ $dbTrendClass($highTrend) }}" title="High / critical opened in the last 7 days against the 7 days before">
            {{ $dbArrow($highTrend) }}
            @if($highTrend['pct'] !== null) {{ $highTrend['pct'] }}% opened vs last week @else {{ $m['flow']['high'][0] }} opened this week @endif
        </div>
        <div class="db-kpi-spark"></div>
    </a>

    {{-- Overdue Actions: active and past their due date (same definition as the Action Center) --}}
    <a @if($links['overdue']) href="{{ $links['overdue'] }}" @endif class="db-kpi" title="Open actions past their due date">
        <div class="db-kpi-label">Overdue Actions</div>
        <div class="db-kpi-value {{ $overdueCount > 0 ? 'db-red' : '' }}">{{ number_format($overdueCount) }}</div>
        <div class="db-kpi-trend db-trend-flat">Past their due date</div>
        <div class="db-kpi-spark"></div>
    </a>

    {{-- Recovered MTD (attributed — analyst/action confirmed) --}}
    <a @if($links['recovered_mtd']) href="{{ $links['recovered_mtd'] }}" @endif class="db-kpi" title="Recovery measured against what sales would have been, this month. Figures entered by hand count only once measured.">
        <div class="db-kpi-label">Recovered MTD</div>
        <div class="db-kpi-value db-green">{{ $dbFormatMoney((float)$recoveredMTD) }}</div>
        <div class="db-kpi-trend {{ $dbTrendClass($recTrend) }}" title="Month to date against the same days of last month">
            {{ $dbArrow($recTrend) }}
            @if($recTrend['pct'] !== null) {{ $recTrend['pct'] }}% vs same point last month @else Measured · month to date @endif
        </div>
        @if(($k['claimed_mtd'] ?? 0) > 0)
            <div class="db-kpi-trend db-trend-flat" title="Entered on outcomes by hand; counted once the measurement confirms it">+ {{ $dbFormatMoney((float) $k['claimed_mtd']) }} claimed, not yet measured</div>
        @endif
        <div class="db-kpi-spark">{!! $dbSparkline($recSparkData, '#16a34a') !!}</div>
    </a>

    {{-- Observed Cleared MTD (R3 — data-only lifecycle recovery, no cause claimed) --}}
    <a @if($links['cleared_mtd']) href="{{ $links['cleared_mtd'] }}" @endif class="db-kpi" title="Value no longer at risk because anomalies cleared and stayed clear — observed, not attributed to any action">
        <div class="db-kpi-label">Observed Cleared MTD</div>
        <div class="db-kpi-value db-teal">{{ $dbFormatMoney((float)$observedClearedMTD) }}</div>
        <div class="db-kpi-trend {{ $dbTrendClass($obsTrend) }}" title="Month to date against the same days of last month">
            {{ $dbArrow($obsTrend) }}
            @if($obsTrend['pct'] !== null) {{ $obsTrend['pct'] }}% vs same point last month @else Data-only · month to date @endif
        </div>
        <div class="db-kpi-spark">{!! $dbSparkline($obsSparkData, '#0d9488') !!}</div>
    </a>

</div>

{{-- W10: the tenant's own KPIs (Root Cause → Custom KPIs) --}}
@if(! empty($customKpis))
<div style="display:flex;align-items:baseline;justify-content:space-between;margin:1.25rem 0 .5rem;">
    <div style="font-size:.8rem;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:#6b7280;">Your KPIs</div>
    @if($customKpisUrl)<a href="{{ $customKpisUrl }}" style="font-size:.8rem;color:#6d28d9;text-decoration:none;">Edit KPIs</a>@endif
</div>
<div class="db-kpi-grid">
    @foreach($customKpis as $kpi)
        <div class="db-kpi" title="{{ $kpi['description'] ?? $kpi['label'] }}">
            <div class="db-kpi-label">{{ $kpi['label'] }}</div>
            <div class="db-kpi-value">{{ \App\Platform\Extensibility\CustomRuleEngine::formatValue($kpi['value'], (string) $kpi['unit'], $currency, display: true) }}</div>
            <div class="db-kpi-trend db-trend-flat">{{ \Illuminate\Support\Str::limit($kpi['description'] ?? 'Your formula', 60) }}</div>
        </div>
    @endforeach
</div>
@endif

{{-- ════════════════════════════════════════════════════════════════════════
     CHARTS SECTION
     ════════════════════════════════════════════════════════════════════════ --}}
<div class="db-charts-grid">

    {{-- Revenue Impact area chart --}}
    <div class="db-card">
        <div class="db-card-head">
            <svg style="width:.875rem;height:.875rem;color:#6d28d9" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"/>
            </svg>
            Revenue Impact — 30 Days
        </div>
        <div class="db-card-body">
            <div class="db-chart-wrap db-chart-wrap-lg">
                <canvas id="db-revenue-chart"></canvas>
            </div>
        </div>
    </div>

    {{-- Status donut --}}
    <div class="db-card">
        <div class="db-card-head">
            <svg style="width:.875rem;height:.875rem;color:#6b7280" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z"/>
            </svg>
            By Status
        </div>
        <div class="db-card-body">
            <div class="db-chart-wrap db-chart-wrap-sm">
                <canvas id="db-status-chart"></canvas>
            </div>
        </div>
    </div>

    {{-- Top Drivers --}}
    <div class="db-card">
        <div class="db-card-head">
            <svg style="width:.875rem;height:.875rem;color:#6b7280" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
            </svg>
            Top Drivers
        </div>
        <div class="db-card-body" style="padding-top:.625rem;padding-bottom:.625rem">
            @if(count($topDrivers))
            <table class="db-driver-table">
                <tbody>
                @foreach($topDrivers as $driver)
                @php
                    $pct = round(($driver['cnt'] / $totalDriverAnomalies) * 100);
                    $driverUrl = $links['drivers'][$driver['rule_type']] ?? null;
                @endphp
                <tr @if($driverUrl) class="db-driver-link" data-href="{{ $driverUrl }}" tabindex="0" role="link" aria-label="View {{ $dbRuleLabel($driver['rule_type']) }} anomalies" title="View {{ $dbRuleLabel($driver['rule_type']) }} anomalies" @endif>
                    <td style="width:40%">
                        <span class="db-driver-name">{{ $dbRuleLabel($driver['rule_type']) }}</span>
                    </td>
                    <td style="width:40%">
                        <div class="db-driver-bar-wrap">
                            <div class="db-driver-bar" style="width:{{ $pct }}%"></div>
                        </div>
                    </td>
                    <td class="db-driver-pct">{{ $pct }}%</td>
                </tr>
                @endforeach
                </tbody>
            </table>
            @else
            <div class="db-empty">No anomaly data yet.</div>
            @endif
        </div>
    </div>

</div>

{{-- ════════════════════════════════════════════════════════════════════════
     BOTTOM: Recent High Priority + Action Center
     ════════════════════════════════════════════════════════════════════════ --}}
<div class="db-bottom-grid">

    {{-- Recent High Priority --}}
    <div class="db-card">
        <div class="db-card-head">
            <svg style="width:.875rem;height:.875rem;color:#f59e0b" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
            Recent High Priority
        </div>
        @if($recentHighPriority->count())
        <div style="overflow-x:auto">
            <table class="db-inv-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Store / SKU</th>
                        <th>Driver</th>
                        <th>Risk</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($recentHighPriority as $inv)
                @php
                    $invUrl = $canInvestigate ? \App\Filament\Resources\InvestigationResource::getUrl('investigate', ['record' => $inv->id]) : null;
                    $priClass = match($inv->priority) {
                        'critical' => 'db-dot-critical',
                        'high'     => 'db-dot-high',
                        'medium'   => 'db-dot-medium',
                        default    => 'db-dot-low',
                    };
                    $stBadge = match($inv->status) {
                        'open'        => 'db-badge-warning',
                        'in_progress' => 'db-badge-info',
                        'resolved'    => 'db-badge-success',
                        default       => 'db-badge-gray',
                    };
                    $primaryAnomaly = $inv->anomalies->sortByDesc(fn($a) => ['critical'=>4,'high'=>3,'medium'=>2,'low'=>1][$a->severity] ?? 0)->first();
                @endphp
                <tr @if($invUrl) data-href="{{ $invUrl }}" style="cursor:pointer" @endif>
                    <td>
                        <span class="db-dot {{ $priClass }}"></span>
                        @if($invUrl)<a href="{{ $invUrl }}" class="db-inv-id" wire:navigate>#{{ $inv->id }}</a>@else<span class="db-inv-id">#{{ $inv->id }}</span>@endif
                    </td>
                    <td>
                        <div style="font-size:.8rem;font-weight:500;color:var(--ax-ink)">
                            {{ $inv->primaryStore?->name ?? ($inv->primary_sku ?? '—') }}
                        </div>
                        @if($inv->primaryStore && $inv->primary_sku)
                        <div class="db-inv-sub">{{ $inv->primary_sku }}</div>
                        @endif
                    </td>
                    <td style="font-size:.775rem;color:var(--ax-muted)">
                        {{ $primaryAnomaly ? $dbRuleLabel($primaryAnomaly->rule_type) : '—' }}
                    </td>
                    <td style="font-weight:700;color:var(--ax-danger);white-space:nowrap;font-size:.8rem">
                        {{ $inv->revenue_at_risk ? $dbTableMoney((float)$inv->revenue_at_risk) : '—' }}
                    </td>
                    <td><span class="db-badge {{ $stBadge }}">{{ $dbStatusLabel($inv->status) }}</span></td>
                </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @else
        <div class="db-empty">No high-priority investigations open. 🎉</div>
        @endif
    </div>

    {{-- Action Center --}}
    <div class="db-card">
        <div class="db-card-head">
            <svg style="width:.875rem;height:.875rem;color:#6d28d9" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
            </svg>
            Action Center
            @if($pendingActions->count())
            <span class="db-badge db-badge-warning" style="margin-left:auto">{{ $pendingActions->count() >= 6 ? 'next 6' : $pendingActions->count() . ' pending' }}</span>
            @endif
        </div>
        @forelse($pendingActions as $action)
        @php
            // Overdue = past its due date, as the KPI and the Action Center count it.
            $isOverdue = $action->due_at && $action->due_at->isPast();
            $overdueFor = $isOverdue ? $action->due_at->diffForHumans(null, \Carbon\CarbonInterface::DIFF_ABSOLUTE, true) : null;
            $dueIn = $action->due_at && ! $isOverdue ? $action->due_at->diffForHumans(null, \Carbon\CarbonInterface::DIFF_ABSOLUTE, true) : null;
            $invPriority = $action->investigation?->priority ?? 'medium';
            $dotCls = match($invPriority) {
                'critical' => 'db-dot-critical',
                'high'     => 'db-dot-high',
                'medium'   => 'db-dot-medium',
                default    => 'db-dot-low',
            };
            $actionUrl = $action->investigation && $canInvestigate
                ? \App\Filament\Resources\InvestigationResource::getUrl('investigate', ['record' => $action->investigation_id])
                : null;
        @endphp
        <div class="db-action-item">
            <span class="db-dot {{ $dotCls }}" style="margin-top:.3rem"></span>
            <div class="db-action-meta">
                <div class="db-action-title">{{ $action->title }}</div>
                <div class="db-action-sub">
                    Investigation #{{ $action->investigation_id }}
                    @if($isOverdue)
                    · <span class="db-sla db-sla-overdue">⚠ overdue by {{ $overdueFor }}</span>
                    @elseif($dueIn)
                    · <span class="db-sla db-sla-ok">due in {{ $dueIn }}</span>
                    @else
                    · <span class="db-sla db-sla-ok">no due date</span>
                    @endif
                </div>
            </div>
            @if($actionUrl)
            <div class="db-action-btns">
                <a href="{{ $actionUrl }}" class="db-btn-sm db-btn-outline-purple" wire:navigate>View</a>
            </div>
            @endif
        </div>
        @empty
        <div class="db-empty">No pending actions — all clear! ✓</div>
        @endforelse
    </div>

</div>

{{-- ════════════════════════════════════════════════════════════════════════
     INSIGHTS & PATTERNS CAROUSEL
     ════════════════════════════════════════════════════════════════════════ --}}
<div class="db-insights">
    <div class="db-insights-title">
        <svg style="width:1rem;height:1rem;color:#9ca3af" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
        </svg>
        Insights &amp; Patterns
    </div>
    <div class="db-carousel-wrap">
        <div class="db-carousel">

            @if($recurringTop)
            <div class="db-insight-card">
                <div class="db-insight-top db-insight-top-red"></div>
                <div class="db-insight-body">
                    <div class="db-insight-icon">🔁</div>
                    <div class="db-insight-label">Recurring Pattern</div>
                    <div class="db-insight-title">{{ $dbRuleLabel($recurringTop['rule_type']) }}</div>
                    <div class="db-insight-desc">
                        <strong>{{ number_format($recurringTop['cnt']) }}</strong> active {{ \Illuminate\Support\Str::plural('anomaly', $recurringTop['cnt']) }} of this kind keep coming back. Consider a systemic fix rather than one-off actions.
                    </div>
                    @if($links['recurring'])<a href="{{ $links['recurring'] }}" class="db-insight-link" wire:navigate>View these anomalies →</a>@endif
                </div>
            </div>
            @endif

            @if($storeAlertData)
            <div class="db-insight-card">
                <div class="db-insight-top db-insight-top-orange"></div>
                <div class="db-insight-body">
                    <div class="db-insight-icon">🏪</div>
                    <div class="db-insight-label">Store Alert</div>
                    <div class="db-insight-title">{{ $storeAlertData['name'] }}</div>
                    <div class="db-insight-desc">
                        <strong>{{ number_format($storeAlertData['cnt']) }}</strong> active anomalies detected here in the last 2 weeks — the most of any location.
                    </div>
                    @if($links['store'])<a href="{{ $links['store'] }}" class="db-insight-link" wire:navigate>View this store's open investigations →</a>@endif
                </div>
            </div>
            @endif

            @if($categoryTop)
            <div class="db-insight-card">
                <div class="db-insight-top db-insight-top-green"></div>
                <div class="db-insight-body">
                    <div class="db-insight-icon">📈</div>
                    <div class="db-insight-label">Top Category This Month</div>
                    <div class="db-insight-title">{{ $dbRuleLabel($categoryTop['rule_type']) }}</div>
                    <div class="db-insight-desc">
                        The most common active anomaly detected this month, with <strong>{{ number_format($categoryTop['cnt']) }}</strong> occurrences. Review thresholds if this is expected behaviour.
                    </div>
                    @if($links['month_top'])<a href="{{ $links['month_top'] }}" class="db-insight-link" wire:navigate>View these anomalies →</a>@endif
                </div>
            </div>
            @endif

            {{-- Always-present summary card --}}
            <div class="db-insight-card">
                <div class="db-insight-top" style="background:var(--ax-accent)"></div>
                <div class="db-insight-body">
                    <div class="db-insight-icon">📊</div>
                    <div class="db-insight-label">Period Summary</div>
                    <div class="db-insight-title">{{ number_format($openCount) }} open · {{ number_format($highPriorityCount) }} high priority</div>
                    <div class="db-insight-desc">
                        {{ $dbFormatMoney((float)$revenueAtRisk) }} revenue currently at risk.
                        @if($recoveredMTD > 0) {{ $dbFormatMoney((float)$recoveredMTD) }} recovered this month. @endif
                    </div>
                    @if($links['open_all'])<a href="{{ $links['open_all'] }}" class="db-insight-link" wire:navigate>Open investigations →</a>@endif
                </div>
            </div>

            @if(!$recurringTop && !$storeAlertData && !$categoryTop)
            <div class="db-insight-card">
                <div class="db-insight-top" style="background:var(--ax-line)"></div>
                <div class="db-insight-body">
                    <div class="db-insight-icon">✅</div>
                    <div class="db-insight-label">All Clear</div>
                    <div class="db-insight-title">No recurring patterns detected</div>
                    <div class="db-insight-desc">No significant recurring patterns or store alerts at this time. Insights will appear here as data accumulates.</div>
                </div>
            </div>
            @endif

        </div>
    </div>
</div>

{{-- ════════════════════════════════════════════════════════════════════════
     CHART.JS SCRIPTS
     ════════════════════════════════════════════════════════════════════════ --}}
<script>
function initDashboardCharts(Chart) {
    if (!Chart) return;

    /* SPA-safe: destroy any existing dashboard charts before re-creating them.
       This script runs each time the dashboard's HTML is rendered (first load
       and every SPA navigation back to it); it registers no global listener. */
    document.querySelectorAll('canvas[id^="db-"]').forEach(function (cv) {
        var existing = Chart.getChart(cv);
        if (existing) existing.destroy();
    });

    const pfx = @json(\App\Support\Money::displayPrefix($currency));

    /* ── Check dark mode ─────────────────────────────────────────────── */
    /* Colours come from the design tokens, so dark mode follows autnyx-ui.css. */
    const css = getComputedStyle(document.documentElement);
    const tok = function (name, fallback) { return (css.getPropertyValue(name) || '').trim() || fallback; };
    const gridColor  = tok('--ax-chart-grid', '#e5e7eb');
    const tickColor  = tok('--ax-chart-axis', '#858c99');
    const tooltipBg  = tok('--ax-bg', '#fff');
    const tooltipClr = tok('--ax-ink', '#111827');
    const tooltipBdr = tok('--ax-line', '#e5e7eb');

    /* ── Revenue chart ───────────────────────────────────────────────── */
    const revCtx = document.getElementById('db-revenue-chart');
    if (revCtx) {
        new Chart(revCtx, {
            type: 'line',
            data: {
                labels: @json($chartLabels),
                datasets: [
                    {
                        label: 'New at risk',
                        data: @json($chartAtRisk),
                        borderColor: '#7c3aed',
                        backgroundColor: 'rgba(124,58,237,.12)',
                        fill: true,
                        tension: .35,
                        pointRadius: 0,
                        pointHoverRadius: 4,
                        borderWidth: 2,
                    },
                    {
                        label: 'Recovered',
                        data: @json($chartRecovered),
                        borderColor: '#16a34a',
                        backgroundColor: 'rgba(22,163,74,.08)',
                        fill: true,
                        tension: .35,
                        pointRadius: 0,
                        pointHoverRadius: 4,
                        borderWidth: 2,
                        borderDash: [4, 3],
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        position: 'top',
                        align: 'end',
                        labels: { color: tickColor, font: { size: 11 }, boxWidth: 12, padding: 10 }
                    },
                    tooltip: {
                        backgroundColor: tooltipBg,
                        titleColor: tooltipClr,
                        bodyColor: tooltipClr,
                        borderColor: tooltipBdr,
                        borderWidth: 1,
                        padding: 10,
                        callbacks: {
                            label: function(ctx) {
                                const v = ctx.raw;
                                if (v >= 1000000) return ctx.dataset.label + ': ' + pfx + (v/1000000).toFixed(2) + 'M';
                                if (v >= 1000)    return ctx.dataset.label + ': ' + pfx + (v/1000).toFixed(1) + 'K';
                                return ctx.dataset.label + ': ' + pfx + v.toFixed(0);
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { color: gridColor },
                        ticks: {
                            color: tickColor, font: { size: 10 },
                            maxTicksLimit: 8, maxRotation: 0,
                        }
                    },
                    y: {
                        grid: { color: gridColor },
                        ticks: {
                            color: tickColor, font: { size: 10 },
                            callback: function(v) {
                                if (v >= 1000000) return pfx+(v/1000000).toFixed(1)+'M';
                                if (v >= 1000)    return pfx+(v/1000).toFixed(0)+'K';
                                return pfx+v;
                            }
                        }
                    }
                }
            }
        });
    }

    /* ── Status donut ─────────────────────────────────────────────────── */
    const statusCtx = document.getElementById('db-status-chart');
    if (statusCtx) {
        const statusMap = {open:'Open',in_progress:'In Progress',resolved:'Resolved',closed:'Closed'};
        const statusKeys = @json(array_keys($statusBreakdown ?: []));
        const statusData = @json(array_values($statusBreakdown ?: []));
        const statusLabels = statusKeys.length ? statusKeys.map(k => statusMap[k] || k) : ['No data'];
        const colorMap = {open:'#f59e0b',in_progress:'#3b82f6',resolved:'#16a34a',closed:'#9ca3af'};
        const statusColors = statusKeys.length ? statusKeys.map(k => colorMap[k] || '#d1d5db') : ['#e5e7eb'];
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: statusLabels,
                datasets: [{
                    data: statusData.length ? statusData : [1],
                    backgroundColor: statusColors,
                    borderWidth: 0,
                    hoverOffset: 4,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { color: tickColor, font: { size: 11 }, boxWidth: 10, padding: 8 }
                    },
                    tooltip: {
                        backgroundColor: tooltipBg,
                        titleColor: tooltipClr,
                        bodyColor: tooltipClr,
                        borderColor: tooltipBdr,
                        borderWidth: 1,
                    }
                }
            }
        });
    }
}
(window.axLoadChartJs ? window.axLoadChartJs() : Promise.reject(new Error('autnyx-ui.js not loaded')))
    .then(initDashboardCharts)
    .catch(function (e) { console.warn('Dashboard charts unavailable:', e.message); });
</script>
