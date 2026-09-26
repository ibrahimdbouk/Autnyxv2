<?php

namespace App\Services\Stores;

use App\Models\Anomaly;
use App\Models\CycleCount;
use App\Models\InvestigationOutcome;
use App\Models\Store;
use App\Models\User;
use App\Support\Detection\ValueModel;
use Illuminate\Support\Facades\URL;

/**
 * W12 — what one store manager needs to see, for their own store(s) only:
 *
 *   findings  live findings at their stores (high and medium), most money
 *             first, those new since the last digest marked;
 *   counts    their open cycle counts;
 *   results   what was measured as recovered at their stores this month.
 *
 * Also the signed links that let them answer without logging in: the store
 * sheet (confirm findings, enter counts) and per-finding Real / Not real.
 */
class StoreDigestService
{
    public const MAX_FINDINGS = 15;
    public const MAX_COUNTS   = 25;
    public const LINK_DAYS    = 7;

    /**
     * @param  \DateTimeInterface|false|null  $since  "new since" cut-off; false = the user's last digest
     * @return array<string,mixed>|null null when there is nothing for this person
     */
    public function forUser(User $user, \DateTimeInterface|false|null $since = false): ?array
    {
        $stores = $user->stores()->where('stores.tenant_id', $user->tenant_id)->get(['stores.id', 'stores.name', 'stores.code']);
        if ($stores->isEmpty()) {
            return null;
        }
        $ids = $stores->pluck('id')->all();
        $since = $since === false ? $user->store_digest_sent_at : ($since ? \Illuminate\Support\Carbon::instance($since) : null);

        $findings = Anomaly::where('tenant_id', $user->tenant_id)->active()
            ->whereIn('store_id', $ids)->whereIn('severity', [Anomaly::SEVERITY_HIGH, Anomaly::SEVERITY_MEDIUM])
            ->get(['id', 'rule_type', 'severity', 'sku', 'store_id', 'description', 'context', 'detected_at', 'feedback', 'value_type'])
            ->sortByDesc(fn (Anomaly $a) => abs(ValueModel::amount((array) $a->context)))
            ->values();

        $counts = CycleCount::where('tenant_id', $user->tenant_id)->where('status', CycleCount::STATUS_OPEN)
            ->whereIn('store_id', $ids)->orderByDesc('value_at_risk')->limit(self::MAX_COUNTS)->get();

        if ($findings->isEmpty() && $counts->isEmpty()) {
            return null;
        }

        $recovered = (float) InvestigationOutcome::where('tenant_id', $user->tenant_id)
            ->where('measured_recovery', '>', 0)->where('recorded_at', '>=', now()->startOfMonth())
            ->whereHas('investigation', fn ($q) => $q->whereIn('primary_store_id', $ids))
            ->sum('measured_recovery');

        return [
            'stores'        => $stores,
            'store_names'   => $stores->pluck('name', 'id')->all(),
            'findings'      => $findings->take(self::MAX_FINDINGS),
            'finding_count' => $findings->count(),
            'new_count'     => $since ? $findings->filter(fn ($a) => $a->detected_at && $a->detected_at->gt($since))->count() : $findings->count(),
            'unanswered'    => $findings->whereNull('feedback')->count(),
            'counts'        => $counts,
            'count_total'   => CycleCount::where('tenant_id', $user->tenant_id)->where('status', CycleCount::STATUS_OPEN)->whereIn('store_id', $ids)->count(),
            'recovered_mtd' => round($recovered, 2),
            'since'         => $since,
        ];
    }

    /** A login-free link to this person's store sheet (their stores only). */
    public function sheetUrl(User $user): string
    {
        return URL::temporarySignedRoute('store-sheet.show', now()->addDays(self::LINK_DAYS), ['user' => $user->id]);
    }

    public function feedbackUrl(User $user, Anomaly $anomaly, string $verdict): string
    {
        return URL::temporarySignedRoute('feedback.show', now()->addDays(14),
            ['anomaly' => $anomaly->id, 'verdict' => $verdict, 'r' => 'user:' . $user->id]);
    }

    public function unsubscribeUrl(User $user): string
    {
        return URL::signedRoute('digest.unsubscribe', ['kind' => 'store', 'id' => $user->id]);
    }

    /** @return array<int,int> the store ids this person may act on through a signed link */
    public static function storeIds(User $user): array
    {
        return $user->stores()->where('stores.tenant_id', $user->tenant_id)->pluck('stores.id')->map(fn ($id) => (int) $id)->all();
    }

    public static function storeLabel(Store $s): string
    {
        return $s->name . ($s->code ? " ({$s->code})" : '');
    }
}
