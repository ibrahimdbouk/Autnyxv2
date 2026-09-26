<?php

namespace App\Services\Fx;

use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * W13 — exchange rates into a tenant's own currency. A tenant reports in one
 * currency (tenants.currency); a store or a sales line in another currency is
 * converted when the daily aggregate is built, and a PO in another currency
 * when it is imported, at the rate in force on the day. No rate → the amount
 * is left as it is and a data check says which currency lacks a rate.
 */
class FxService
{
    /**
     * Currencies pegged to the US dollar (units per USD) — long-standing pegs,
     * seeded on request so a UAE/Saudi/Qatari/Omani/Bahraini chain works on day
     * one. The Kuwaiti dinar is on a basket, not a peg: enter it by hand.
     */
    public const USD_PEGS = ['USD' => 1.0, 'AED' => 3.6725, 'SAR' => 3.75, 'QAR' => 3.64, 'OMR' => 0.3845, 'BHD' => 0.376];

    public function base(int $tenantId): string
    {
        return Money::normalize(DB::table('tenants')->where('id', $tenantId)->value('currency'));
    }

    /** Tenant-currency units per 1 unit of $currency on $date (null: unknown). */
    public function rate(int $tenantId, string $currency, string $date): ?float
    {
        $currency = strtoupper(trim($currency));
        if ($currency === '' || $currency === $this->base($tenantId)) {
            return 1.0;
        }
        $r = DB::table('fx_rates')->where('tenant_id', $tenantId)->where('currency', $currency)
            ->where('valid_from', '<=', $date)->orderByDesc('valid_from')->value('rate');

        return $r !== null ? (float) $r : null;
    }

    /** Does any sales line or store in this range use a currency other than the tenant's? */
    public function needsConversion(int $tenantId, string $from, string $to): bool
    {
        $base = $this->base($tenantId);

        return DB::table('stores')->where('tenant_id', $tenantId)->whereNotNull('currency')->whereRaw("upper(currency) <> ? AND currency <> ''", [$base])->exists()
            || DB::table('sales_transactions')->where('tenant_id', $tenantId)->whereBetween('date', [$from, $to])
                ->whereNotNull('currency')->whereRaw("upper(currency) <> ? AND currency <> ''", [$base])->exists();
    }

    /** @return array<int,string> currencies in use (stores, sales, POs) with no rate at all */
    public function missing(int $tenantId): array
    {
        $base = $this->base($tenantId);
        $used = collect(DB::select(
            "SELECT DISTINCT upper(c) AS c FROM (
                SELECT currency AS c FROM stores WHERE tenant_id = ? AND currency IS NOT NULL
                UNION SELECT DISTINCT currency FROM purchase_orders WHERE tenant_id = ? AND currency IS NOT NULL
                UNION SELECT currency FROM (SELECT DISTINCT currency FROM sales_transactions WHERE tenant_id = ? AND currency IS NOT NULL AND date >= current_date - 120) x
             ) u WHERE c <> ''",
            [$tenantId, $tenantId, $tenantId]
        ))->pluck('c')->reject(fn ($c) => $c === $base);
        $have = DB::table('fx_rates')->where('tenant_id', $tenantId)->distinct()->pluck('currency')->all();

        return $used->diff($have)->values()->all();
    }

    /** Seed the USD pegs for a tenant whose own currency is pegged (never overwrites a rate). */
    public function seedPegs(int $tenantId): int
    {
        $base = $this->base($tenantId);
        if (! isset(self::USD_PEGS[$base])) {
            return 0;
        }
        $n = 0;
        foreach (self::USD_PEGS as $cur => $perUsd) {
            if ($cur === $base) {
                continue;
            }
            $n += DB::table('fx_rates')->insertOrIgnore([
                'tenant_id' => $tenantId, 'currency' => $cur, 'valid_from' => '2000-01-01',
                'rate' => round(self::USD_PEGS[$base] / $perUsd, 8), 'source' => 'peg',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $n;
    }
}
