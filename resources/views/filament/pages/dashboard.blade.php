<x-filament-panels::page>

@php
/* ── Tenant & time anchors ─────────────────────────────────────────────── */
$tenantId    = \Filament\Facades\Filament::getTenant()?->id;
$now         = now();
$monthStart  = $now->copy()->startOfMonth();
$weekAgo     = $now->copy()->subDays(7);
$twoWeeksAgo = $now->copy()->subDays(14);

/* ── KPI: Revenue at Risk ──────────────────────────────────────────────── */
$revenueAtRisk = $tenantId
    ? (\App\Models\Investigation::where('tenant_id',$tenantId)
        ->whereIn('status',['open','in_progress'])
        ->sum('revenue_at_risk') ?? 0)
    : 0;
$prevRevenueAtRisk = $tenantId
    ? (\App\Models\Investigation::where('tenant_id',$tenantId)
        ->where('opened_at','>=',$twoWeeksAgo)
        ->where('opened_at','<',$weekAgo)
        ->sum('revenue_at_risk') ?? 0)
    : 0;

/* ── KPI: Open Investigations ──────────────────────────────────────────── */
$openCount = $tenantId
    ? \App\Models\Investigation::where('tenant_id',$tenantId)
        ->whereIn('status',['open','in_progress'])->count()
    : 0;
$prevOpenCount = $tenantId
    ? \App\Models\Investigation::where('tenant_id',$tenantId)
        ->where('opened_at','>=',$twoWeeksAgo)
        ->where('opened_at','<',$weekAgo)->count()
    : 0;

/* ── KPI: High Priority ────────────────────────────────────────────────── */
$highPriorityCount = $tenantId
    ? \App\Models\Investigation::where('tenant_id',$tenantId)
        ->whereIn('status',['open','in_progress'])
        ->whereIn('priority',['high','critical'])->count()
    : 0;
$prevHighCount = $tenantId
    ? \App\Models\Investigation::where('tenant_id',$tenantId)
        ->where('opened_at','>=',$twoWeeksAgo)
        ->where('opened_at','<',$weekAgo)
        ->whereIn('priority',['high','critical'])->count()
    : 0;

/* ── KPI: Overdue Actions ──────────────────────────────────────────────── */
$overdueCount = $tenantId
    ? \App\Models\Action::whereHas('investigation', fn($q) =>
        $q->where('tenant_id',$tenantId)->whereIn('status',['open','in_progress']))
        ->where('status','pending')
        ->where('created_at','<', $now->copy()->subHours(48))->count()
    : 0;

/* ── KPI: Recovered MTD ────────────────────────────────────────────────── */
$recoveredMTD = $tenantId
    ? (\App\Models\InvestigationOutcome::whereHas('investigation', fn($q) =>
        $q->where('tenant_id',$tenantId))
        ->where('created_at','>=',$monthStart)
        ->sum('observed_recovery') ?? 0)
    : 0;
$prevRecoveredMTD = $tenantId
    ? (\App\Models\InvestigationOutcome::whereHas('investigation', fn($q) =>
        $q->where('tenant_id',$tenantId))
        ->where('created_at','>=',$now->copy()->subMonth()->startOfMonth())
        ->where('created_at','<',$monthStart)
        ->sum('observed_recovery') ?? 0)
    : 0;

/* ── KPI: Observed Cleared MTD (R3 — data-only, from the anomaly lifecycle) ──
   Value that stopped being at risk because the condition cleared and stayed
   clear across evaluated runs. OBSERVED, no cause claimed — deliberately
   separate from the attributed "Recovered MTD" above; the two are never summed. */
$recoverySvc = app(\App\Services\Recovery\AnomalyRecoveryService::class);
$observedClearedMTD     = $tenantId ? (float) $recoverySvc->mtd($tenantId)['amount'] : 0;
$prevObservedClearedMTD = $tenantId ? (float) $recoverySvc->prevMtd($tenantId)['amount'] : 0;
$observedClearedDaily   = $tenantId ? $recoverySvc->dailySeries($tenantId, 30) : [];

/* ── Status breakdown (for donut chart) ───────────────────────────────── */
$statusBreakdown = $tenantId
    ? \App\Models\Investigation::where('tenant_id',$tenantId)
        ->selectRaw('status, count(*) as cnt')
        ->groupBy('status')
        ->pluck('cnt','status')
        ->toArray()
    : [];

/* ── Revenue daily for 30-day chart ───────────────────────────────────── */
$revenueDailyRaw = $tenantId
    ? \App\Models\Investigation::where('tenant_id',$tenantId)
        ->where('opened_at','>=', $now->copy()->subDays(29)->startOfDay())
        ->whereNotNull('revenue_at_risk')
        ->selectRaw("TO_CHAR(opened_at::date,'YYYY-MM-DD') as date, SUM(revenue_at_risk) as total")
        ->groupByRaw("TO_CHAR(opened_at::date,'YYYY-MM-DD')")
        ->pluck('total','date')
        ->toArray()
    : [];

$recoveryDailyRaw = $tenantId
    ? \App\Models\InvestigationOutcome::whereHas('investigation', fn($q) =>
        $q->where('tenant_id',$tenantId))
        ->where('created_at','>=',$now->copy()->subDays(29)->startOfDay())
        ->whereNotNull('observed_recovery')
        ->selectRaw("TO_CHAR(created_at::date,'YYYY-MM-DD') as date, SUM(observed_recovery) as total")
        ->groupByRaw("TO_CHAR(created_at::date,'YYYY-MM-DD')")
        ->pluck('total','date')
        ->toArray()
    : [];

$chartLabels = [];
$chartAtRisk = [];
$chartRecovered = [];
$chartObsCleared = [];
for ($i = 29; $i >= 0; $i--) {
    $d = $now->copy()->subDays($i)->format('Y-m-d');
    $chartLabels[]     = $now->copy()->subDays($i)->format('M j');
    $chartAtRisk[]     = round($revenueDailyRaw[$d] ?? 0, 2);
    $chartRecovered[]  = round($recoveryDailyRaw[$d] ?? 0, 2);
    $chartObsCleared[] = round($observedClearedDaily[$d] ?? 0, 2);
}

/* ── Top drivers ───────────────────────────────────────────────────────── */
$topDrivers = $tenantId
    ? \App\Models\Anomaly::where('tenant_id',$tenantId)
        ->whereNull('dismissed_at')
        ->selectRaw('rule_type, count(*) as cnt')
        ->groupBy('rule_type')
        ->orderByDesc('cnt')
        ->limit(5)
        ->get()
    : collect();
$totalDriverAnomalies = $topDrivers->sum('cnt') ?: 1;

/* ── Recent high-priority investigations ───────────────────────────────── */
$recentHighPriority = $tenantId
    ? \App\Models\Investigation::where('tenant_id',$tenantId)
        ->whereIn('priority',['critical','high'])
        ->whereIn('status',['open','in_progress'])
        ->with(['assignedTeam','anomalies'])
        ->orderByDesc('opened_at')
        ->limit(6)
        ->get()
    : collect();

/* ── Pending actions ───────────────────────────────────────────────────── */
$pendingActions = $tenantId
    ? \App\Models\Action::whereHas('investigation', fn($q) =>
        $q->where('tenant_id',$tenantId)->whereIn('status',['open','in_progress']))
        ->where('status','pending')
        ->with(['investigation'])
        ->orderBy('created_at')
        ->limit(6)
        ->get()
    : collect();

/* ── Insights ──────────────────────────────────────────────────────────── */
$recurringTop = $tenantId
    ? \App\Models\Anomaly::where('tenant_id',$tenantId)
        ->where('ai_is_recurring', true)
        ->whereNull('dismissed_at')
        ->selectRaw('rule_type, count(*) as cnt')
        ->groupBy('rule_type')
        ->orderByDesc('cnt')
        ->first()
    : null;

$storeAlertData = null;
if ($tenantId) {
    $storeAgg = \App\Models\Anomaly::where('tenant_id',$tenantId)
        ->whereNull('dismissed_at')
        ->whereNotNull('store_id')
        ->where('detected_at','>=',$twoWeeksAgo)
        ->selectRaw('store_id, count(*) as cnt')
        ->groupBy('store_id')
        ->orderByDesc('cnt')
        ->first();
    if ($storeAgg) {
        $storeAlertData = ['store' => \App\Models\Store::find($storeAgg->store_id), 'cnt' => $storeAgg->cnt];
    }
}

$categoryTop = $tenantId
    ? \App\Models\Anomaly::where('tenant_id',$tenantId)
        ->whereNull('dismissed_at')
        ->where('detected_at','>=',$monthStart)
        ->selectRaw('rule_type, count(*) as cnt')
        ->groupBy('rule_type')
        ->orderByDesc('cnt')
        ->first()
    : null;

/* ── Format helpers (closures — safe for multi-render Livewire cycles) ── */
$dbCurrency = \App\Support\Money::normalize(\Filament\Facades\Filament::getTenant()?->currency);
$dbFormatMoney = static function(float $val) use ($dbCurrency): string {
    return \App\Support\Money::compact($val, $dbCurrency);
};

$dbTrend = static function(float $current, float $prev): array {
    if ($prev == 0) return ['pct' => null, 'dir' => 'flat'];
    $pct = round((($current - $prev) / abs($prev)) * 100, 1);
    return ['pct' => abs($pct), 'dir' => $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'flat')];
};

$riskTrend   = $dbTrend((float)$revenueAtRisk, (float)$prevRevenueAtRisk);
$openTrend   = $dbTrend((float)$openCount, (float)$prevOpenCount);
$highTrend   = $dbTrend((float)$highPriorityCount, (float)$prevHighCount);
$recTrend    = $dbTrend((float)$recoveredMTD, (float)$prevRecoveredMTD);
$obsTrend    = $dbTrend((float)$observedClearedMTD, (float)$prevObservedClearedMTD);

$dbRuleLabel = static function(string $ruleType): string {
    $map = [
        'sales_velocity_drop'       => 'Sales Velocity Drop',
        'stockout_risk'             => 'Stockout Risk',
        'overstock_risk'            => 'Overstock Risk',
        'po_delay'                  => 'PO Delay',
        'return_spike'              => 'Return Spike',
        'low_margin_sku'            => 'Low Margin SKU',
        'inventory_shrinkage'       => 'Inventory Shrinkage',
        'demand_spike'              => 'Demand Spike',
        'price_anomaly'             => 'Price Anomaly',
        'replenishment_miss'        => 'Replenishment Miss',
    ];
    return $map[$ruleType] ?? ucwords(str_replace('_',' ',$ruleType));
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

// Simple sparklines: status distribution values
$statusValues = array_values($statusBreakdown ?: [0]);
$riskSparkData  = array_slice($chartAtRisk, -7);
$recSparkData   = array_slice($chartRecovered, -7);
$obsSparkData   = array_slice($chartObsCleared, -7);

/* ── Drill-down URLs for the KPI cards ─────────────────────────────────── */
$urlRevenueAtRisk = \App\Filament\Pages\FinancialBreakdown::getUrl(['metric' => 'revenue_at_risk']);
$urlRecoveredMtd  = \App\Filament\Pages\FinancialBreakdown::getUrl(['metric' => 'recovered_mtd']);
$urlObservedCleared = \App\Filament\Pages\FinancialBreakdown::getUrl(['metric' => 'observed_cleared']);
$urlOpenInv       = \App\Filament\Resources\InvestigationResource::getUrl('index', ['status' => 'open']);
$urlHighPriority  = \App\Filament\Resources\InvestigationResource::getUrl('index', ['status' => 'open', 'priority' => 'high_critical']);
$urlOverdue       = \App\Filament\Pages\ActionCenter::getUrl(['tab' => 'overdue']);
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
@media(min-width:1024px) { .db-kpi-grid { grid-template-columns:repeat(5,1fr); } }
/* ── Clickable KPI card (anchor) ──────────────────────────────────────── */
a.db-kpi {
    text-decoration:none; color:inherit; position:relative;
    cursor:pointer;
    transition:box-shadow .15s ease, transform .15s ease, border-color .15s ease;
}
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
     KPI CARDS
     ════════════════════════════════════════════════════════════════════════ --}}
<div class="db-kpi-grid">

    {{-- Revenue at Risk --}}
    <a href="{{ $urlRevenueAtRisk }}" class="db-kpi" title="See how Revenue at Risk is derived">
        <div class="db-kpi-label">Revenue at Risk</div>
        <div class="db-kpi-value">{{ $dbFormatMoney((float)$revenueAtRisk) }}</div>
        <div class="db-kpi-trend {{ $riskTrend['dir'] === 'up' ? 'db-trend-up' : ($riskTrend['dir'] === 'down' ? 'db-trend-down' : 'db-trend-flat') }}">
            @if($riskTrend['dir'] === 'up') ↑ @elseif($riskTrend['dir'] === 'down') ↓ @else — @endif
            @if($riskTrend['pct'] !== null) {{ $riskTrend['pct'] }}% vs prev period @else New metric @endif
        </div>
        <div class="db-kpi-spark">{!! $dbSparkline($riskSparkData, '#dc2626') !!}</div>
    </a>

    {{-- Open Investigations --}}
    <a href="{{ $urlOpenInv }}" class="db-kpi" title="View open investigations">
        <div class="db-kpi-label">Open Investigations</div>
        <div class="db-kpi-value">{{ $openCount }}</div>
        <div class="db-kpi-trend {{ $openTrend['dir'] === 'up' ? 'db-trend-up' : ($openTrend['dir'] === 'down' ? 'db-trend-down' : 'db-trend-flat') }}">
            @if($openTrend['dir'] === 'up') ↑ @elseif($openTrend['dir'] === 'down') ↓ @else — @endif
            @if($openTrend['pct'] !== null) {{ $openTrend['pct'] }}% vs prev period @else No prior data @endif
        </div>
        <div class="db-kpi-spark">{!! $dbSparkline(array_values(array_slice($statusBreakdown ?: [0], 0, 7)), '#3b82f6') !!}</div>
    </a>

    {{-- High Priority --}}
    <a href="{{ $urlHighPriority }}" class="db-kpi" title="View high &amp; critical priority investigations">
        <div class="db-kpi-label">High Priority</div>
        <div class="db-kpi-value" style="color:#f59e0b">{{ $highPriorityCount }}</div>
        <div class="db-kpi-trend {{ $highTrend['dir'] === 'up' ? 'db-trend-up' : ($highTrend['dir'] === 'down' ? 'db-trend-down' : 'db-trend-flat') }}">
            @if($highTrend['dir'] === 'up') ↑ @elseif($highTrend['dir'] === 'down') ↓ @else — @endif
            @if($highTrend['pct'] !== null) {{ $highTrend['pct'] }}% vs prev period @else No prior data @endif
        </div>
        <div class="db-kpi-spark">{!! $dbSparkline(array_values(array_slice($chartAtRisk, -7)), '#f59e0b') !!}</div>
    </a>

    {{-- Overdue Actions --}}
    <a href="{{ $urlOverdue }}" class="db-kpi" title="View overdue actions in the Action Center">
        <div class="db-kpi-label">Overdue Actions</div>
        <div class="db-kpi-value" style="{{ $overdueCount > 0 ? 'color:#dc2626' : '' }}">{{ $overdueCount }}</div>
        <div class="db-kpi-trend db-trend-flat">Actions pending &gt;48h</div>
        <div class="db-kpi-spark">{!! $dbSparkline([$overdueCount, $overdueCount], '#dc2626') !!}</div>
    </a>

    {{-- Recovered MTD (attributed — analyst/action confirmed) --}}
    <a href="{{ $urlRecoveredMtd }}" class="db-kpi" title="See how Recovered MTD is derived">
        <div class="db-kpi-label">Recovered MTD</div>
        <div class="db-kpi-value" style="color:#16a34a">{{ $dbFormatMoney((float)$recoveredMTD) }}</div>
        <div class="db-kpi-trend {{ $recTrend['dir'] === 'up' ? 'db-trend-up' : ($recTrend['dir'] === 'down' ? 'db-trend-down' : 'db-trend-flat') }}">
            @if($recTrend['dir'] === 'up') ↑ @elseif($recTrend['dir'] === 'down') ↓ @else — @endif
            @if($recTrend['pct'] !== null) {{ $recTrend['pct'] }}% vs prev month @else Attributed · month to date @endif
        </div>
        <div class="db-kpi-spark">{!! $dbSparkline($recSparkData, '#16a34a') !!}</div>
    </a>

    {{-- Observed Cleared MTD (R3 — data-only lifecycle recovery, no cause claimed) --}}
    <a href="{{ $urlObservedCleared }}" class="db-kpi" title="Value no longer at risk because anomalies cleared and stayed clear — observed, not attributed to any action">
        <div class="db-kpi-label">Observed Cleared MTD</div>
        <div class="db-kpi-value" style="color:#0d9488">{{ $dbFormatMoney((float)$observedClearedMTD) }}</div>
        <div class="db-kpi-trend {{ $obsTrend['dir'] === 'up' ? 'db-trend-up' : ($obsTrend['dir'] === 'down' ? 'db-trend-down' : 'db-trend-flat') }}">
            @if($obsTrend['dir'] === 'up') ↑ @elseif($obsTrend['dir'] === 'down') ↓ @else — @endif
            @if($obsTrend['pct'] !== null) {{ $obsTrend['pct'] }}% vs prev month @else Data-only · month to date @endif
        </div>
        <div class="db-kpi-spark">{!! $dbSparkline($obsSparkData, '#0d9488') !!}</div>
    </a>

</div>

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
            @if($topDrivers->count())
            <table class="db-driver-table">
                <tbody>
                @foreach($topDrivers as $driver)
                @php
                    $pct = round(($driver->cnt / $totalDriverAnomalies) * 100);
                    $driverUrl = \App\Filament\Resources\AnomalyResource::getUrl('index', ['tableFilters' => ['rule_type' => ['value' => $driver->rule_type]]]);
                @endphp
                <tr class="db-driver-link" onclick="window.location.href='{{ $driverUrl }}'" onkeydown="if(event.key==='Enter'){window.location.href='{{ $driverUrl }}'}" tabindex="0" role="link" aria-label="View {{ $dbRuleLabel($driver->rule_type) }} anomalies" title="View {{ $dbRuleLabel($driver->rule_type) }} anomalies">
                    <td style="width:40%">
                        <span class="db-driver-name">{{ $dbRuleLabel($driver->rule_type) }}</span>
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
                    $invUrl = \App\Filament\Resources\InvestigationResource::getUrl('investigate', ['record' => $inv->id]);
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
                <tr onclick="window.location.href='{{ $invUrl }}'" style="cursor:pointer">
                    <td>
                        <span class="db-dot {{ $priClass }}"></span>
                        <a href="{{ $invUrl }}" class="db-inv-id" onclick="event.stopPropagation()">#{{ $inv->id }}</a>
                    </td>
                    <td>
                        <div style="font-size:.8rem;font-weight:500;color:#111827">
                            {{ $inv->primaryStore?->name ?? ($inv->primary_sku ?? '—') }}
                        </div>
                        @if($inv->primaryStore && $inv->primary_sku)
                        <div class="db-inv-sub">{{ $inv->primary_sku }}</div>
                        @endif
                    </td>
                    <td style="font-size:.775rem;color:#6b7280">
                        {{ $primaryAnomaly ? $dbRuleLabel($primaryAnomaly->rule_type) : '—' }}
                    </td>
                    <td style="font-weight:700;color:#dc2626;white-space:nowrap;font-size:.8rem">
                        {{ $inv->revenue_at_risk ? $dbFormatMoney((float)$inv->revenue_at_risk) : '—' }}
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
            <span class="db-badge db-badge-warning" style="margin-left:auto">{{ $pendingActions->count() }} pending</span>
            @endif
        </div>
        @forelse($pendingActions as $action)
        @php
            $ageHours = $action->created_at ? now()->diffInHours($action->created_at) : 0;
            $isOverdue = $ageHours >= 48;
            $invPriority = $action->investigation?->priority ?? 'medium';
            $dotCls = match($invPriority) {
                'critical' => 'db-dot-critical',
                'high'     => 'db-dot-high',
                'medium'   => 'db-dot-medium',
                default    => 'db-dot-low',
            };
            $actionUrl = $action->investigation
                ? \App\Filament\Resources\InvestigationResource::getUrl('investigate', ['record' => $action->investigation_id])
                : '#';
        @endphp
        <div class="db-action-item">
            <span class="db-dot {{ $dotCls }}" style="margin-top:.3rem"></span>
            <div class="db-action-meta">
                <div class="db-action-title">{{ $action->title }}</div>
                <div class="db-action-sub">
                    Investigation #{{ $action->investigation_id }}
                    @if($isOverdue)
                    · <span class="db-sla db-sla-overdue">⚠ {{ round($ageHours) }}h overdue</span>
                    @else
                    · <span class="db-sla db-sla-ok">{{ round($ageHours) }}h ago</span>
                    @endif
                </div>
            </div>
            <div class="db-action-btns">
                <a href="{{ $actionUrl }}" class="db-btn-sm db-btn-outline-purple">View</a>
            </div>
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
                    <div class="db-insight-title">{{ $dbRuleLabel($recurringTop->rule_type) }}</div>
                    <div class="db-insight-desc">
                        This issue has recurred <strong>{{ $recurringTop->cnt }}</strong> time(s). Consider a systemic fix rather than one-off actions.
                    </div>
                    <a href="{{ \App\Filament\Resources\InvestigationResource::getUrl('index') }}" class="db-insight-link">View investigations →</a>
                </div>
            </div>
            @endif

            @if($storeAlertData && $storeAlertData['store'])
            <div class="db-insight-card">
                <div class="db-insight-top db-insight-top-orange"></div>
                <div class="db-insight-body">
                    <div class="db-insight-icon">🏪</div>
                    <div class="db-insight-label">Store Alert</div>
                    <div class="db-insight-title">{{ $storeAlertData['store']->name }}</div>
                    <div class="db-insight-desc">
                        This store has generated <strong>{{ $storeAlertData['cnt'] }}</strong> anomalies in the last 2 weeks — the highest of any location.
                    </div>
                    <a href="{{ \App\Filament\Resources\InvestigationResource::getUrl('index') }}" class="db-insight-link">View store investigations →</a>
                </div>
            </div>
            @endif

            @if($categoryTop)
            <div class="db-insight-card">
                <div class="db-insight-top db-insight-top-green"></div>
                <div class="db-insight-body">
                    <div class="db-insight-icon">📈</div>
                    <div class="db-insight-label">Top Category This Month</div>
                    <div class="db-insight-title">{{ $dbRuleLabel($categoryTop->rule_type) }}</div>
                    <div class="db-insight-desc">
                        The most common anomaly this month with <strong>{{ $categoryTop->cnt }}</strong> occurrences. Review thresholds if this is expected behaviour.
                    </div>
                    <a href="{{ \App\Filament\Resources\InvestigationResource::getUrl('index') }}" class="db-insight-link">View anomalies →</a>
                </div>
            </div>
            @endif

            {{-- Always-present summary card --}}
            <div class="db-insight-card">
                <div class="db-insight-top" style="background:#6d28d9"></div>
                <div class="db-insight-body">
                    <div class="db-insight-icon">📊</div>
                    <div class="db-insight-label">Period Summary</div>
                    <div class="db-insight-title">{{ $openCount }} open · {{ $highPriorityCount }} high priority</div>
                    <div class="db-insight-desc">
                        {{ $dbFormatMoney((float)$revenueAtRisk) }} revenue currently at risk.
                        @if($recoveredMTD > 0) {{ $dbFormatMoney((float)$recoveredMTD) }} recovered this month. @endif
                    </div>
                    <a href="{{ \App\Filament\Resources\InvestigationResource::getUrl('index') }}" class="db-insight-link">Open investigations →</a>
                </div>
            </div>

            @if(!$recurringTop && !$storeAlertData && !$categoryTop)
            <div class="db-insight-card">
                <div class="db-insight-top" style="background:#d1d5db"></div>
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
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js" defer></script>
<script>
document.addEventListener('DOMContentLoaded', function () {

    /* ── Check dark mode ─────────────────────────────────────────────── */
    const isDark = document.documentElement.classList.contains('dark');
    const gridColor  = isDark ? 'rgba(255,255,255,.07)' : 'rgba(0,0,0,.06)';
    const tickColor  = isDark ? '#6b7280' : '#9ca3af';
    const tooltipBg  = isDark ? '#1f2937' : '#fff';
    const tooltipClr = isDark ? '#f3f4f6' : '#111827';
    const tooltipBdr = isDark ? '#374151' : '#e5e7eb';

    /* ── Revenue chart ───────────────────────────────────────────────── */
    const revCtx = document.getElementById('db-revenue-chart');
    if (revCtx) {
        new Chart(revCtx, {
            type: 'line',
            data: {
                labels: @json($chartLabels),
                datasets: [
                    {
                        label: 'Revenue at Risk',
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
                                if (v >= 1000000) return ctx.dataset.label + ': $' + (v/1000000).toFixed(2) + 'M';
                                if (v >= 1000)    return ctx.dataset.label + ': $' + (v/1000).toFixed(1) + 'K';
                                return ctx.dataset.label + ': $' + v.toFixed(0);
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
                                var pfx = @json(\App\Support\Money::prefix(\Filament\Facades\Filament::getTenant()?->currency));
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

});
</script>

</x-filament-panels::page>
