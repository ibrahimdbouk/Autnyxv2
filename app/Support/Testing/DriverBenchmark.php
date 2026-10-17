<?php

namespace App\Support\Testing;

use App\Models\Store;
use App\Services\Drivers\DriverAnalysis;
use App\Services\Sales\SalesDailyAggregator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Root Cause drivers — proof that the breakdown blames the right driver.
 *
 * A synthetic retailer with one store per planted change (and day-to-day
 * noise everywhere): fewer shoppers, a price rise, out-of-stock days, a
 * promotion that ended, a mix shift to dearer products, a product launch.
 * Each store's breakdown should put the change (or, price aside, its
 * non-price part) on the planted driver.
 *
 *   attributed = the planted driver's value ÷ the change it should explain
 *   pass       = attributed ≥ 85%
 */
class DriverBenchmark
{
    public const PASS = 0.85;

    /** Comparison: the 4 weeks before; period: one week. */
    public const C_FROM = '2026-08-03';
    public const C_TO = '2026-08-30';
    public const P_FROM = '2026-08-31';
    public const P_TO = '2026-09-06';

    private const PRICES = ['P1' => 5, 'P2' => 8, 'P3' => 10, 'P4' => 12, 'P5' => 15, 'P6' => 20];

    /** scenario => [driver, part of the breakdown, what it should explain: change | non_price] */
    public const SCENARIOS = [
        'fewer_shoppers' => ['traffic', 'what', 'change'],
        'price_rise'     => ['price', 'what', 'change'],
        'out_of_stock'   => ['availability', 'why', 'non_price'],
        'promotion_end'  => ['promotions', 'why', 'non_price'],
        'mix_shift'      => ['mix', 'what', 'change'],
        'launch'         => ['range', 'why', 'non_price'],
    ];

    private int $receipt = 0;

    /** @var array<string,array<int,array>> */
    private array $buffer = [];

    /** @return array<string,array{driver:string, change:float, explained:float, attributed:float, pass:bool}> */
    public function run(int $tenantId, int $seed = 7): array
    {
        mt_srand($seed);
        foreach (self::PRICES + ['P7' => 9] as $sku => $price) {
            DB::table('products')->insertOrIgnore(['tenant_id' => $tenantId, 'sku' => $sku, 'name' => "Product {$sku}",
                'department' => in_array($sku, ['P1', 'P2', 'P3', 'P7'], true) ? 'Grocery' : 'Dairy', 'category' => 'Cat ' . $sku,
                'selling_price' => $price, 'unit_cost' => $price / 2, 'created_at' => now(), 'updated_at' => now()]);
        }
        $stores = [];
        foreach (array_keys(self::SCENARIOS) as $i => $name) {
            $stores[$name] = Store::firstOrCreate(['tenant_id' => $tenantId, 'code' => 'BM' . ($i + 1)], ['name' => 'Bench ' . str_replace('_', ' ', $name)]);
        }
        DB::table('promotions')->insert(['tenant_id' => $tenantId, 'promotion_ref' => 'BM-P3', 'sku' => 'P3', 'store_id' => $stores['promotion_end']->id,
            'starts_on' => '2026-08-17', 'ends_on' => self::C_TO, 'created_at' => now(), 'updated_at' => now()]);

        for ($d = Carbon::parse(self::C_FROM); $d->lte(Carbon::parse(self::P_TO)); $d->addDay()) {
            $date = $d->toDateString();
            foreach ($stores as $name => $store) {
                $this->day($tenantId, $store, $name, $date);
            }
            $this->flush();
        }
        $this->flush();
        app(SalesDailyAggregator::class)->aggregateRange($tenantId, self::C_FROM, self::P_TO);

        $out = [];
        $engine = app(DriverAnalysis::class);
        foreach (self::SCENARIOS as $name => [$driver, $part, $base]) {
            $r = $engine->analyze($tenantId, self::P_FROM, self::P_TO, self::C_FROM, self::C_TO, ['store_id' => $stores[$name]->id]);
            $parts = DriverAnalysis::map($r[$part]);
            $change = (float) $r['totals']['change'];
            $target = $base === 'change' ? $change : $change - DriverAnalysis::map($r['what'])['price'];
            $value = (float) ($parts[$driver] ?? 0);
            $att = abs($target) > 0.005 && ($value > 0) === ($target > 0) ? min(1.0, abs($value) / abs($target)) : 0.0;
            $out[$name] = ['driver' => $driver, 'change' => round($target, 2), 'explained' => round($value, 2), 'attributed' => round($att, 3), 'pass' => $att >= self::PASS];
        }

        return $out;
    }

    /**
     * A normal day: ~30 receipts (±1 by weekday, the noise), each buying two products
     * (every product ~10 a day). The store's planted change applies in the
     * period (the promotion: in the comparison's last two weeks).
     */
    private function day(int $tenantId, Store $store, string $scenario, string $date): void
    {
        $inP = $date >= self::P_FROM;
        // Day-to-day noise that nets out over a week, so the comparison's pace
        // and the period's differ only by what was planted.
        $receipts = 30 + [-1, 0, 1, 0, -1, 1, 0][Carbon::parse($date)->dayOfWeekIso - 1];
        if ($scenario === 'fewer_shoppers' && $inP) {
            $receipts = (int) round($receipts * 0.8);
        }
        $out = $scenario === 'out_of_stock' && $inP && $date >= '2026-09-02' && $date <= '2026-09-04';
        $promo = $scenario === 'promotion_end' && $date >= '2026-08-17' && $date <= self::C_TO;
        $skus = array_keys(self::PRICES);
        for ($i = 0; $i < $receipts; $i++) {
            $lines = [];
            foreach ([$skus[$i % 6], $skus[($i + 3) % 6]] as $sku) {
                if ($scenario === 'mix_shift' && $inP && $sku === 'P1') {
                    $sku = 'P6';
                }
                if ($out && $sku === 'P2') {
                    continue;
                }
                $price = self::PRICES[$sku] * ($scenario === 'price_rise' && $inP && in_array($sku, ['P4', 'P5', 'P6'], true) ? 1.1 : 1);
                $qty = $promo && $sku === 'P3' ? 2 : 1;
                $lines[] = [$sku, $qty, $price];
            }
            if ($scenario === 'launch' && $inP && $i % 3 === 0) {
                $lines[] = ['P7', 1, 9];
            }
            $this->receiptRows($tenantId, $store, $date, $lines);
        }
        foreach ($skus as $sku) {
            $this->buffer['inventory_levels'][] = ['tenant_id' => $tenantId, 'store_id' => $store->id, 'sku' => $sku, 'as_of_date' => $date,
                'on_hand_qty' => $out && $sku === 'P2' ? 0 : 60, 'created_at' => now(), 'updated_at' => now()];
        }
    }

    private function receiptRows(int $tenantId, Store $store, string $date, array $lines): void
    {
        $id = 'BM' . $tenantId . '-' . (++$this->receipt);
        foreach ($lines as $n => [$sku, $qty, $price]) {
            $this->buffer['sales_transactions'][] = ['tenant_id' => $tenantId, 'store_id' => $store->id, 'transaction_id' => $id, 'line_no' => $n + 1,
                'date' => $date, 'sku' => $sku, 'quantity' => $qty, 'unit_price' => $price, 'total_amount' => round($qty * $price, 4),
                'created_at' => now(), 'updated_at' => now()];
        }
    }

    private function flush(): void
    {
        foreach ($this->buffer as $table => $rows) {
            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }
        $this->buffer = [];
    }
}
