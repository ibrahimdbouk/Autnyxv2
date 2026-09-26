<?php

namespace App\Console\Commands;

use App\Models\AssortmentGap;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The validation gate's worksheet: the top decisions of one type for a tenant,
 * ranked by the middle of their value range and weighted by confidence, as a
 * table or CSV for a category manager to mark sensible / not sensible.
 */
class ReviewAssortmentCommand extends Command
{
    protected $signature = 'assortment:review
        {--tenant= : Tenant id (required)}
        {--type=add : add | delist | stockout_hidden}
        {--limit=50 : How many}
        {--csv= : Write a CSV to this path instead of printing}';

    protected $description = 'List the top Assortment decisions for review (validation gate).';

    public function handle(): int
    {
        $tenantId = (int) $this->option('tenant');
        $type = (string) $this->option('type');
        if ($tenantId <= 0 || ! array_key_exists($type, AssortmentGap::TYPES)) {
            $this->error('Pass --tenant=<id> and --type=add|delist|stockout_hidden.');

            return self::INVALID;
        }

        $rows = DB::table('assortment_gaps as g')
            ->join('stores as s', 's.id', '=', 'g.store_id')
            ->leftJoin('products as p', 'p.id', '=', 'g.product_id')
            ->where('g.tenant_id', $tenantId)->where('g.type', $type)
            ->orderByRaw('g.value_mid * g.confidence DESC')
            ->limit(max(1, (int) $this->option('limit')))
            ->get(['s.name as store', 'g.sku', 'p.name as product', 'p.category', 'g.value_low', 'g.value_mid', 'g.value_high',
                'g.confidence_tier', 'g.explanation']);

        $out = $rows->map(function ($r) {
            $e = json_decode((string) $r->explanation, true) ?: [];

            return [
                'store' => $r->store, 'sku' => $r->sku, 'product' => $r->product, 'category' => $r->category,
                'value_low' => round((float) $r->value_low), 'value_mid' => round((float) $r->value_mid), 'value_high' => round((float) $r->value_high),
                'confidence' => $r->confidence_tier,
                'why' => implode(' ', $e['evidence'] ?? []),
                'sensible (y/n)' => '',
            ];
        })->all();

        if ($path = $this->option('csv')) {
            $fh = fopen($path, 'w');
            fputcsv($fh, array_keys($out[0] ?? ['store' => '']));
            foreach ($out as $line) {
                fputcsv($fh, $line);
            }
            fclose($fh);
            $this->info(count($out) . " rows written to {$path}");

            return self::SUCCESS;
        }

        if ($out === []) {
            $this->warn('No decisions of this type yet — run assortment:run first.');

            return self::SUCCESS;
        }
        $this->table(['store', 'sku', 'product', 'category', 'low', 'mid', 'high', 'confidence'],
            array_map(fn ($r) => array_slice(array_values($r), 0, 8), $out));

        return self::SUCCESS;
    }
}
