{{-- Dashboard "operations pulse": stat tiles that reflect the newer capabilities —
     autonomy/detection status, intra-network transfer capacity (Deep Investigation),
     AI-narration coverage, and recovery this month. Cheap tenant-scoped queries. --}}
@php
    $opTenant = \Filament\Facades\Filament::getTenant();
    $opT = $opTenant?->id;
    $opCurrency = $opTenant?->currencyCode() ?? 'AED';

    $opLastDetection = $opTenant?->last_detection_at;

    $opTransferStores = 0;
    $opTransferUnits = 0;
    $opNarratedPct = null;
    $opRecoveryMtd = 0.0;

    if ($opT) {
        try {
            $capBase = \App\Models\SkuReplenishment::where('tenant_id', $opT)
                ->where('order_up_to', '>', 0)
                ->whereColumn('on_hand', '>', 'order_up_to');
            $opTransferStores = (clone $capBase)->distinct()->count('store_id');
            $opTransferUnits = (int) (clone $capBase)->selectRaw('COALESCE(SUM(on_hand - order_up_to),0) as s')->value('s');
        } catch (\Throwable $e) { /* leave zeros */ }

        try {
            $openBase = \App\Models\Investigation::where('tenant_id', $opT)->whereIn('status', ['open', 'in_progress']);
            $openCount = (clone $openBase)->count();
            $narrated = (clone $openBase)->whereNotNull('ai_generated_at')->count();
            $opNarratedPct = $openCount > 0 ? (int) round($narrated / $openCount * 100) : null;
        } catch (\Throwable $e) { /* leave null */ }

        try {
            $opRecoveryMtd = (float) \App\Models\InvestigationOutcome::where('tenant_id', $opT)
                ->where('recorded_at', '>=', now()->startOfMonth())
                ->sum('observed_recovery');
        } catch (\Throwable $e) { /* leave zero */ }
    }
@endphp

<style>
.opp-grid{display:grid; grid-template-columns:repeat(4,1fr); gap:.7rem; margin-bottom:1.1rem;}
@media(max-width:820px){ .opp-grid{grid-template-columns:repeat(2,1fr);} }
.opp-tile{background:var(--ax-bg); border:1px solid var(--ax-line); border-radius:.8rem; box-shadow:var(--ax-shadow); padding:.85rem 1rem;}
.opp-lab{font-size:.66rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--ax-faint); display:flex; align-items:center; gap:.35rem;}
.opp-val{font-size:1.3rem; font-weight:800; color:var(--ax-ink); margin-top:.3rem; line-height:1.1;}
.opp-sub{font-size:.72rem; color:var(--ax-muted); margin-top:.2rem;}
.opp-dot{width:.5rem; height:.5rem; border-radius:9999px; display:inline-block;}
.opp-dot.ok{background:#16a34a;} .opp-dot.warn{background:#d97706;} .opp-dot.idle{background:#9ca3af;}
</style>

<div class="opp-grid">
    <div class="opp-tile">
        <div class="opp-lab">
            <span class="opp-dot {{ $opLastDetection && $opLastDetection->gt(now()->subDay()) ? 'ok' : ($opLastDetection ? 'warn' : 'idle') }}"></span>
            Detection
        </div>
        <div class="opp-val">{{ $opLastDetection ? $opLastDetection->diffForHumans(null, true) : '—' }}</div>
        <div class="opp-sub">{{ $opLastDetection ? 'since last run' : 'no run recorded yet' }}</div>
    </div>

    <div class="opp-tile">
        <div class="opp-lab">🔁 Transfer capacity</div>
        <div class="opp-val">{{ number_format($opTransferUnits) }}</div>
        <div class="opp-sub">releasable units across {{ number_format($opTransferStores) }} store{{ $opTransferStores === 1 ? '' : 's' }}</div>
    </div>

    <div class="opp-tile">
        <div class="opp-lab">🧠 AI-narrated</div>
        <div class="opp-val">{{ $opNarratedPct === null ? '—' : $opNarratedPct . '%' }}</div>
        <div class="opp-sub">of open investigations</div>
    </div>

    <div class="opp-tile">
        <div class="opp-lab">↩ Recovery (MTD)</div>
        <div class="opp-val">{{ \App\Support\Money::compact($opRecoveryMtd, $opCurrency) }}</div>
        <div class="opp-sub">recorded this month</div>
    </div>
</div>
