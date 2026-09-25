<?php

namespace App\Services\DataQuality;

use App\Models\DqFinding;
use App\Models\EntityAlias;
use App\Models\QuarantinedRow;
use App\Support\Tenancy\TenantClock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * W9 (WP9.3) — semantic and cross-dataset data-quality checks.
 *
 * Row validation (the firewall) catches a malformed row; these catch data that
 * is well-formed but wrong in a way that would mislead detection: a store that
 * stopped sending sales, a day that was only half loaded, sales dated in the
 * future, prices ten times the list price, stock snapshots that stopped
 * moving for one store, sold products with no cost, quarantine left to rot,
 * and unknown SKUs that are almost certainly a known SKU written differently.
 *
 * Each check reads the aggregates (sales_daily, inventory_current), never the
 * raw transaction table, so a run is cheap enough to follow every import and
 * the nightly chain. Every finding is keyed (check, subject); a run re-sees
 * or resolves each one, and a newly critical finding alerts.
 */
class DataQualityChecks
{
    public const CHECKS = [
        'store_went_silent', 'sales_day_collapse', 'future_dated', 'negative_stock',
        'stock_stale_store', 'price_outliers', 'cost_missing', 'quarantine_aging', 'alias_suggestions',
    ];

    public const LABELS = [
        'store_went_silent'  => 'Store stopped sending sales',
        'sales_day_collapse' => 'Latest sales day looks partly loaded',
        'future_dated'       => 'Rows dated in the future',
        'negative_stock'     => 'Negative stock on hand',
        'stock_stale_store'  => 'Store stock snapshot not updating',
        'price_outliers'     => 'Selling prices far from list price',
        'cost_missing'       => 'Sold products without a unit cost',
        'quarantine_aging'   => 'Quarantined rows waiting too long',
        'alias_suggestions'  => 'Unknown SKUs that match a known SKU',
    ];

    /** @var array<string,true> keys seen in this run */
    private array $seen = [];

    /** @var array<int,DqFinding> */
    private array $opened = [];

    public function __construct(private ReadinessAlerter $alerter, private AliasSuggester $aliases)
    {
    }

    /** @return array{open:int, opened:int, resolved:int} */
    public function run(int $tenantId, bool $alert = true): array
    {
        $this->seen = [];
        $this->opened = [];

        foreach (self::CHECKS as $check) {
            try {
                $this->{'check' . str_replace('_', '', ucwords($check, '_'))}($tenantId);
            } catch (\Throwable $e) {
                // One broken check never hides the others — and never resolves its own open findings.
                Log::warning('[dq] check failed', ['tenant' => $tenantId, 'check' => $check, 'error' => $e->getMessage()]);
                DqFinding::where('tenant_id', $tenantId)->where('check', $check)->open()->get()
                    ->each(fn ($f) => $this->seen[$f->check . '|' . $f->subject_key] = true);
            }
        }

        $resolved = 0;
        foreach (DqFinding::where('tenant_id', $tenantId)->open()->whereIn('check', self::CHECKS)->get() as $f) {
            if (! isset($this->seen[$f->check . '|' . $f->subject_key])) {
                $f->update(['resolved_at' => now()]);
                $resolved++;
            }
        }

        if ($alert) {
            $critical = array_filter($this->opened, fn ($f) => $f->severity === DqFinding::SEVERITY_CRITICAL);
            if ($critical !== []) {
                $this->alerter->alert($tenantId, 'Data check — ' . (count($critical) === 1 ? self::LABELS[reset($critical)->check] : count($critical) . ' critical findings'),
                    implode(' ', array_map(fn ($f) => $f->message, array_slice($critical, 0, 3))) . ' See Data Health → Data checks.');
            }
        }

        return [
            'open'     => DqFinding::where('tenant_id', $tenantId)->open()->count(),
            'opened'   => count($this->opened),
            'resolved' => $resolved,
        ];
    }

    // ── Checks ───────────────────────────────────────────────────────────────

    /** A store that sold on most days of the last month but not in the last two days the tenant has data for. */
    private function checkStoreWentSilent(int $tenantId): void
    {
        $max = $this->maxSalesDate($tenantId);
        if (! $max) {
            return;
        }
        $rows = DB::select(
            'SELECT sd.store_id, s.name, COUNT(DISTINCT sd.date) FILTER (WHERE sd.date <= ?) AS active_days, MAX(sd.date) AS last_date
             FROM sales_daily sd LEFT JOIN stores s ON s.id = sd.store_id
             WHERE sd.tenant_id = ? AND sd.store_id IS NOT NULL AND sd.date BETWEEN ? AND ?
             GROUP BY sd.store_id, s.name
             HAVING COUNT(DISTINCT sd.date) FILTER (WHERE sd.date <= ?) >= 20 AND MAX(sd.date) < ?',
            [$max->copy()->subDays(3)->toDateString(), $tenantId, $max->copy()->subDays(30)->toDateString(), $max->toDateString(),
                $max->copy()->subDays(3)->toDateString(), $max->copy()->subDay()->toDateString()],
        );
        foreach ($rows as $r) {
            $name = $r->name ?: "Store #{$r->store_id}";
            $this->see($tenantId, 'store_went_silent', 'sales', DqFinding::SEVERITY_WARNING, 'store:' . $r->store_id, $name,
                "{$name} sold on {$r->active_days} of the previous 28 days but has no sales since {$r->last_date} (latest data: {$max->toDateString()}). Its POS feed may have stopped; sales-drop findings for it are not reliable.",
                ['store_id' => (int) $r->store_id, 'last_date' => $r->last_date, 'active_days' => (int) $r->active_days]);
        }
    }

    /** The latest day's units far below the same weekday in the previous four weeks. */
    private function checkSalesDayCollapse(int $tenantId): void
    {
        $max = $this->maxSalesDate($tenantId);
        if (! $max) {
            return;
        }
        $days = [$max->toDateString()];
        for ($w = 1; $w <= 4; $w++) {
            $days[] = $max->copy()->subWeeks($w)->toDateString();
        }
        $units = collect(DB::select('SELECT date::text AS d, SUM(units_sold) AS u FROM sales_daily WHERE tenant_id = ? AND date IN (' . implode(',', array_fill(0, 5, '?')) . ') GROUP BY date',
            array_merge([$tenantId], $days)))->mapWithKeys(fn ($r) => [substr($r->d, 0, 10) => (float) $r->u]);

        $prior = collect(array_slice($days, 1))->map(fn ($d) => $units[$d] ?? null)->filter(fn ($u) => $u !== null)->sort()->values();
        if ($prior->count() < 3) {
            return;
        }
        $median = $prior->count() % 2 ? $prior[intdiv($prior->count(), 2)] : ($prior[$prior->count() / 2 - 1] + $prior[$prior->count() / 2]) / 2;
        $latest = $units[$days[0]] ?? 0.0;
        if ($median < 20 || $latest >= $median * 0.4) {
            return;
        }
        $pct = (int) round(100 * $latest / $median);
        $this->see($tenantId, 'sales_day_collapse', 'sales', $latest < $median * 0.15 ? DqFinding::SEVERITY_CRITICAL : DqFinding::SEVERITY_WARNING,
            'date:' . $days[0], $days[0],
            "Sales on {$days[0]} are {$pct}% of a normal {$max->englishDayOfWeek} (" . number_format($latest) . ' vs ' . number_format($median) . ' units). If the day is only partly loaded, re-send it; until then sales-drop findings for that day are suspect.',
            ['date' => $days[0], 'units' => $latest, 'median_same_weekday' => $median]);
    }

    private function checkFutureDated(int $tenantId): void
    {
        $limit = TenantClock::today($tenantId)->addDay()->toDateString();
        foreach ([
            'sales'     => ['sales_daily', 'date'],
            'inventory' => ['inventory_current', 'as_of_date'],
        ] as $dataset => [$table, $col]) {
            $r = DB::selectOne("SELECT COUNT(*) AS n, MAX({$col})::text AS latest FROM {$table} WHERE tenant_id = ? AND {$col} > ?", [$tenantId, $limit]);
            if ((int) $r->n > 0) {
                $this->see($tenantId, 'future_dated', $dataset, DqFinding::SEVERITY_CRITICAL, $dataset, ucfirst($dataset),
                    number_format($r->n) . " {$dataset} row(s) are dated after tomorrow (latest " . substr($r->latest, 0, 10) . '). Usually a day/month mix-up in the file — they distort "latest day" and trend checks. Roll back that import or fix the date format.',
                    ['rows' => (int) $r->n, 'latest' => substr($r->latest, 0, 10)]);
            }
        }
    }

    private function checkNegativeStock(int $tenantId): void
    {
        $r = DB::selectOne('SELECT COUNT(*) AS n, COUNT(DISTINCT store_id) AS stores FROM inventory_current WHERE tenant_id = ? AND on_hand_qty < 0', [$tenantId]);
        if ((int) $r->n === 0) {
            return;
        }
        $sample = DB::table('inventory_current')->where('tenant_id', $tenantId)->where('on_hand_qty', '<', 0)->orderBy('on_hand_qty')->limit(5)->pluck('sku')->all();
        $this->see($tenantId, 'negative_stock', 'inventory', DqFinding::SEVERITY_WARNING, 'tenant', 'Current stock',
            number_format($r->n) . " position(s) in {$r->stores} store(s) have negative stock on hand (e.g. " . implode(', ', $sample) . '). Sales are being booked against stock the system does not have — receipts or transfers are missing.',
            ['positions' => (int) $r->n, 'stores' => (int) $r->stores, 'sample_skus' => $sample]);
    }

    /** One store's stock snapshot far behind the others'. */
    private function checkStockStaleStore(int $tenantId): void
    {
        $rows = DB::select('SELECT ic.store_id, s.name, MAX(ic.as_of_date)::text AS latest FROM inventory_current ic LEFT JOIN stores s ON s.id = ic.store_id
            WHERE ic.tenant_id = ? AND ic.store_id IS NOT NULL GROUP BY ic.store_id, s.name', [$tenantId]);
        if (count($rows) < 2) {
            return;
        }
        $newest = Carbon::parse(max(array_map(fn ($r) => $r->latest, $rows)));
        foreach ($rows as $r) {
            $latest = Carbon::parse($r->latest);
            if ($latest->lt($newest->copy()->subDays(7))) {
                $name = $r->name ?: "Store #{$r->store_id}";
                $this->see($tenantId, 'stock_stale_store', 'inventory', DqFinding::SEVERITY_WARNING, 'store:' . $r->store_id, $name,
                    "{$name}'s stock snapshot is from " . $latest->toDateString() . ' while other stores are at ' . $newest->toDateString() . '. Its stock-out and overstock findings use old stock.',
                    ['store_id' => (int) $r->store_id, 'latest' => $latest->toDateString(), 'tenant_latest' => $newest->toDateString()]);
            }
        }
    }

    /** Average selling price per store-day more than 5× or under a fifth of the list price. */
    private function checkPriceOutliers(int $tenantId): void
    {
        $max = $this->maxSalesDate($tenantId);
        if (! $max) {
            return;
        }
        $base = 'FROM sales_daily sd JOIN products p ON p.tenant_id = sd.tenant_id AND p.sku = sd.sku
            WHERE sd.tenant_id = ? AND sd.date > ? AND sd.units_sold > 0 AND sd.revenue > 0 AND p.selling_price > 0
              AND (sd.revenue / sd.units_sold > p.selling_price * 5 OR sd.revenue / sd.units_sold < p.selling_price * 0.2)';
        $bind = [$tenantId, $max->copy()->subDays(14)->toDateString()];
        $r = DB::selectOne("SELECT COUNT(*) AS n, COUNT(DISTINCT sd.sku) AS skus {$base}", $bind);
        if ((int) $r->n === 0) {
            return;
        }
        $sample = collect(DB::select("SELECT sd.sku, ROUND((sd.revenue / sd.units_sold)::numeric, 2) AS price, p.selling_price {$base} ORDER BY sd.date DESC LIMIT 5", $bind))
            ->map(fn ($s) => "{$s->sku} sold at {$s->price} (list {$s->selling_price})")->all();
        $this->see($tenantId, 'price_outliers', 'sales', DqFinding::SEVERITY_WARNING, 'tenant', 'Last 14 days',
            number_format($r->n) . " store-day(s) across {$r->skus} SKU(s) sold at more than 5× or under a fifth of list price — e.g. " . implode('; ', array_slice($sample, 0, 3)) . '. Usually pack vs unit quantities, cents vs units, or a stale list price. Revenue and margin findings for them are off.',
            ['store_days' => (int) $r->n, 'skus' => (int) $r->skus, 'sample' => $sample]);
    }

    private function checkCostMissing(int $tenantId): void
    {
        $max = $this->maxSalesDate($tenantId);
        if (! $max) {
            return;
        }
        $r = DB::selectOne('SELECT COUNT(*) AS n FROM products p WHERE p.tenant_id = ? AND COALESCE(p.unit_cost, 0) <= 0
            AND EXISTS (SELECT 1 FROM sales_daily sd WHERE sd.tenant_id = p.tenant_id AND sd.sku = p.sku AND sd.date > ?)',
            [$tenantId, $max->copy()->subDays(28)->toDateString()]);
        if ((int) $r->n === 0) {
            return;
        }
        $this->see($tenantId, 'cost_missing', 'products', DqFinding::SEVERITY_WARNING, 'tenant', 'Product master',
            number_format($r->n) . ' product(s) sold in the last 28 days have no unit cost. Margin, stock value and money-at-risk figures leave them out. Add costs to the product file.',
            ['products' => (int) $r->n]);
    }

    private function checkQuarantineAging(int $tenantId): void
    {
        $days = max(1, (int) config('data_quality.quarantine_aging_days', 7));
        $rows = QuarantinedRow::where('tenant_id', $tenantId)->where('status', QuarantinedRow::STATUS_OPEN)
            ->where('created_at', '<', now()->subDays($days))
            ->selectRaw('data_type, COUNT(*) AS n, MIN(created_at) AS oldest')->groupBy('data_type')->get();
        foreach ($rows as $r) {
            $label = ucwords(str_replace('_', ' ', (string) $r->data_type));
            $this->see($tenantId, 'quarantine_aging', (string) $r->data_type, DqFinding::SEVERITY_WARNING, 'type:' . $r->data_type, $label,
                number_format($r->n) . " quarantined {$label} row(s) have waited more than {$days} days (oldest " . Carbon::parse($r->oldest)->diffForHumans() . '). They are not in detection. Fix and re-screen, or discard them.',
                ['rows' => (int) $r->n, 'oldest' => (string) $r->oldest]);
        }
    }

    private function checkAliasSuggestions(int $tenantId): void
    {
        $s = $this->aliases->suggest($tenantId);
        if ($s === []) {
            return;
        }
        $this->see($tenantId, 'alias_suggestions', 'products', DqFinding::SEVERITY_INFO, 'sku', 'SKU aliases',
            count($s) . ' unknown SKU(s) match a known SKU written differently (e.g. ' . implode(', ', array_map(fn ($x) => "{$x['alias']} → {$x['canonical']}", array_slice($s, 0, 3)))
            . '). Accept them on the Quarantine page so their rows count.',
            ['suggestions' => array_slice($s, 0, 50)]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private ?array $maxDateCache = null;

    private function maxSalesDate(int $tenantId): ?Carbon
    {
        if (($this->maxDateCache[0] ?? null) !== $tenantId) {
            $limit = TenantClock::today($tenantId)->addDay()->toDateString();   // ignore future-dated rows
            $d = DB::table('sales_daily')->where('tenant_id', $tenantId)->where('date', '<=', $limit)->max('date');
            $this->maxDateCache = [$tenantId, $d ? Carbon::parse($d)->startOfDay() : null];
        }

        return $this->maxDateCache[1]?->copy();
    }

    private function see(int $tenantId, string $check, string $dataset, string $severity, string $subjectKey, ?string $subject, string $message, array $metrics): void
    {
        $this->seen[$check . '|' . $subjectKey] = true;
        $f = DqFinding::firstOrNew(['tenant_id' => $tenantId, 'check' => $check, 'subject_key' => $subjectKey]);
        $isNew = ! $f->exists || $f->resolved_at !== null
            || ($f->severity !== DqFinding::SEVERITY_CRITICAL && $severity === DqFinding::SEVERITY_CRITICAL);

        $f->fill([
            'dataset'     => $dataset,
            'severity'    => $severity,
            'subject'     => $subject,
            'message'     => $message,
            'metrics'     => $metrics,
            'last_seen_at'=> now(),
            'resolved_at' => null,
        ]);
        if (! $f->exists || $f->getOriginal('resolved_at') !== null) {
            $f->first_seen_at = now();
            $f->occurrences = $f->exists ? $f->occurrences + 1 : 1;
        } else {
            $f->occurrences = $f->occurrences + 1;
        }
        $f->save();

        if ($isNew) {
            $this->opened[] = $f;
        }
    }
}
