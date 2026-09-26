<?php

namespace App\Http\Controllers;

use App\Models\Anomaly;
use App\Models\CycleCount;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Anomaly\AnomalyFeedback;
use App\Services\Counts\CycleCountService;
use App\Services\Stores\StoreDigestService;
use App\Support\Detection\ValueModel;
use Illuminate\Http\Request;

/**
 * W12 — a store manager's sheet, opened from their digest (e-mail or Teams).
 *
 * The link is signed for one person and expires after 7 days; it shows and
 * accepts answers for THEIR stores only: confirm each finding (real / not
 * real) and enter cycle counts. No password — the signature is the key, the
 * same way the digest's own links work. Everything is recorded against the
 * person the link was issued to, and audited.
 */
class StoreSheetController extends Controller
{
    public function show(Request $request, int $user)
    {
        [$u, $tenant, $storeIds] = $this->resolve($user);
        $stores = $u->stores()->whereIn('stores.id', $storeIds)->get(['stores.id', 'stores.name', 'stores.code']);

        $findings = Anomaly::where('tenant_id', $tenant->id)->active()->whereIn('store_id', $storeIds)
            ->get(['id', 'rule_type', 'severity', 'sku', 'store_id', 'description', 'context', 'feedback', 'detected_at'])
            ->sortByDesc(fn ($a) => abs(ValueModel::amount((array) $a->context)))->take(50)->values();
        $counts = CycleCount::where('tenant_id', $tenant->id)->where('status', CycleCount::STATUS_OPEN)
            ->whereIn('store_id', $storeIds)->orderBy('store_id')->orderBy('rank')->get();
        $counted = CycleCount::where('tenant_id', $tenant->id)->where('status', CycleCount::STATUS_COUNTED)
            ->whereIn('store_id', $storeIds)->where('counted_at', '>=', now()->subDays(7))->orderByDesc('counted_at')->limit(20)->get();
        $names = Product::where('tenant_id', $tenant->id)->whereIn('sku', $counts->pluck('sku')->merge($counted->pluck('sku'))->unique()->values()->all() ?: ['__none__'])
            ->pluck('name', 'sku');

        return response()->view('store-sheet', [
            'user' => $u, 'tenant' => $tenant, 'stores' => $stores, 'storeNames' => $stores->pluck('name', 'id'),
            'findings' => $findings, 'counts' => $counts, 'counted' => $counted, 'names' => $names,
            'action' => $request->fullUrl(),
            'money' => fn (float $v) => \App\Support\Money::compact($v, \App\Support\Money::normalize($tenant->currency)),
        ]);
    }

    public function store(Request $request, int $user, AnomalyFeedback $feedback, CycleCountService $counts)
    {
        [$u, $tenant, $storeIds] = $this->resolve($user);
        $message = null;

        if ($request->input('do') === 'feedback') {
            $verdict = (string) $request->input('verdict');
            $a = Anomaly::where('tenant_id', $tenant->id)->whereIn('store_id', $storeIds)->find((int) $request->input('anomaly'));
            abort_unless($a && in_array($verdict, [AnomalyFeedback::REAL, AnomalyFeedback::NOT_REAL], true), 422);
            $feedback->record($a, $verdict, $u, AnomalyFeedback::VIA_STORE_SHEET, $u->email);
            $message = 'Thanks — saved as ' . ($verdict === AnomalyFeedback::REAL ? 'real' : 'not real') . '.';
        } elseif ($request->input('do') === 'counts') {
            $saved = 0;
            $bad = [];
            foreach ((array) $request->input('counted', []) as $id => $qty) {
                if (! is_scalar($qty) || trim((string) $qty) === '') {
                    continue;
                }
                $c = CycleCount::where('tenant_id', $tenant->id)->where('status', CycleCount::STATUS_OPEN)
                    ->whereIn('store_id', $storeIds)->find((int) $id);
                $q = str_replace(',', '', trim((string) $qty));
                if (! $c) {
                    continue;
                }
                if (! is_numeric($q) || (float) $q < 0) {
                    $bad[] = $c->sku;
                    continue;
                }
                $counts->record($c, (float) $q, $u, 'sheet');
                $saved++;
            }
            $message = $saved . ' count' . ($saved === 1 ? '' : 's') . ' saved.' . ($bad ? ' Not a number for: ' . implode(', ', $bad) . '.' : '');
        }

        return redirect()->to($request->fullUrl())->with('sheet_message', $message);
    }

    /** @return array{0:User, 1:Tenant, 2:array<int,int>} */
    private function resolve(int $userId): array
    {
        $u = User::find($userId);
        $tenant = $u?->tenant_id ? Tenant::find($u->tenant_id) : null;
        abort_unless($u && $tenant && $tenant->isActive(), 403);
        $ids = StoreDigestService::storeIds($u);
        abort_if($ids === [], 403, 'This link is no longer linked to a store.');

        return [$u, $tenant, $ids];
    }
}
