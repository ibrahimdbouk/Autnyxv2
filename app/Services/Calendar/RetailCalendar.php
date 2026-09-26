<?php

namespace App\Services\Calendar;

use App\Models\RetailEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * W13 — a tenant's retail calendar, and whether it explains a demand swing.
 *
 * Each event covers a share of a window's days with its effective span
 * (build-up + event + after-effect). A swing between two windows is explained
 * when an event covers one of them materially more than the other (by at
 * least MIN_DIFF of the window) — Ramadan filling this month but a sliver of
 * the comparison month, Eid in last year's dates but not this year's (the
 * Hijri calendar moves ~11 days a year), back to school now but not in the
 * baseline. Categories and countries on an event narrow what it explains.
 */
final class RetailCalendar
{
    public const MIN_DIFF = 0.3;

    /** @var array<int,array{key:string,name:string,from:string,to:string,countries:?array,categories:?array}> */
    private array $events = [];

    /** @var array<string,?string> sku => lower(department/category) list key */
    private array $skuCats = [];

    /** @var array<int,?string> store id => upper country code */
    private array $storeCountry = [];

    /** @var array<string,bool> countries the tenant trades in */
    private array $tenantCountries = [];

    public static function load(int $tenantId, string $from, string $to): self
    {
        app(RetailCalendarDefaults::class)->ensure($tenantId);
        $cal = new self();
        foreach (RetailEvent::where('tenant_id', $tenantId)->where('active', true)
            ->where('starts_on', '<=', Carbon::parse($to)->addDays(60)->toDateString())
            ->where('ends_on', '>=', Carbon::parse($from)->subDays(60)->toDateString())->get() as $e) {
            $cal->events[] = [
                'key'        => $e->key,
                'name'       => $e->name,
                'from'       => $e->starts_on->copy()->subDays($e->lead_days)->toDateString(),
                'to'         => $e->ends_on->copy()->addDays($e->tail_days)->toDateString(),
                'countries'  => $e->countries ? array_map('strtoupper', $e->countries) : null,
                'categories' => $e->categories ? array_map(fn ($c) => mb_strtolower(trim((string) $c)), $e->categories) : null,
            ];
        }
        if ($cal->events !== []) {
            foreach (DB::table('products')->where('tenant_id', $tenantId)->get(['sku', 'category', 'department', 'subcategory']) as $p) {
                $cal->skuCats[(string) $p->sku] = array_values(array_filter(array_map(fn ($v) => $v !== null ? mb_strtolower(trim((string) $v)) : null,
                    [$p->department, $p->category, $p->subcategory])));
            }
            foreach (DB::table('stores')->where('tenant_id', $tenantId)->get(['id', 'country']) as $s) {
                $cal->storeCountry[(int) $s->id] = $code = self::countryCode($s->country);
                if ($code !== null) {
                    $cal->tenantCountries[$code] = true;
                }
            }
        }

        return $cal;
    }

    public function isEmpty(): bool
    {
        return $this->events === [];
    }

    /**
     * The event that explains a swing between the two windows (both given as
     * inclusive Y-m-d dates), or null.
     *
     * @return array{key:string,name:string,from:string,to:string}|null
     */
    public function explains(?string $sku, ?int $storeId, string $aFrom, string $aTo, string $bFrom, string $bTo): ?array
    {
        $a = $this->shares($sku, $storeId, $aFrom, $aTo);
        $b = $this->shares($sku, $storeId, $bFrom, $bTo);
        $best = null;
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $key) {
            $diff = abs(($a[$key]['share'] ?? 0) - ($b[$key]['share'] ?? 0));
            if ($diff >= self::MIN_DIFF && ($best === null || $diff > $best[0])) {
                $e = $a[$key] ?? $b[$key];
                $best = [$diff, ['key' => $e['key'], 'name' => $e['name'], 'from' => $e['from'], 'to' => $e['to']]];
            }
        }

        return $best[1] ?? null;
    }

    /** @return array<string,array> event key => event + share of [from, to] it covers (largest year) */
    public function shares(?string $sku, ?int $storeId, string $from, string $to): array
    {
        $days = max(1, Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1);
        $out = [];
        foreach ($this->events as $e) {
            if (! $this->appliesTo($e, $sku, $storeId)) {
                continue;
            }
            $lo = max($from, $e['from']);
            $hi = min($to, $e['to']);
            if ($lo > $hi) {
                continue;
            }
            $share = (Carbon::parse($lo)->diffInDays(Carbon::parse($hi)) + 1) / $days;
            if ($share > ($out[$e['key']]['share'] ?? 0)) {
                $out[$e['key']] = $e + ['share' => $share];
            }
        }

        return $out;
    }

    private function appliesTo(array $e, ?string $sku, ?int $storeId): bool
    {
        if ($e['categories'] !== null && $sku !== null) {
            if (array_intersect($e['categories'], $this->skuCats[$sku] ?? []) === []) {
                return false;
            }
        }
        // A country's own event (national day) needs a store known to be there;
        // a chain-level view needs the tenant to trade in one of its countries.
        if ($e['countries'] !== null) {
            if ($storeId !== null) {
                $c = $this->storeCountry[$storeId] ?? null;
                if ($c === null || ! in_array($c, $e['countries'], true)) {
                    return false;
                }
            } elseif (array_intersect($e['countries'], array_keys($this->tenantCountries)) === []) {
                return false;
            }
        }

        return true;
    }

    /** "United Arab Emirates", "UAE", "ae" → "AE" (unknown → the input upper-cased, or null). */
    public static function countryCode(?string $country): ?string
    {
        if ($country === null || trim($country) === '') {
            return null;
        }
        $c = mb_strtolower(trim($country));
        $map = [
            'uae' => 'AE', 'united arab emirates' => 'AE', 'emirates' => 'AE', 'ae' => 'AE',
            'ksa' => 'SA', 'saudi arabia' => 'SA', 'saudi' => 'SA', 'kingdom of saudi arabia' => 'SA', 'sa' => 'SA',
            'kuwait' => 'KW', 'kw' => 'KW', 'qatar' => 'QA', 'qa' => 'QA', 'bahrain' => 'BH', 'bh' => 'BH',
            'oman' => 'OM', 'om' => 'OM', 'egypt' => 'EG', 'eg' => 'EG', 'jordan' => 'JO', 'jo' => 'JO', 'lebanon' => 'LB', 'lb' => 'LB',
        ];

        return $map[$c] ?? mb_strtoupper(mb_substr($c, 0, 2));
    }
}
