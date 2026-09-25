<?php

namespace App\Services\DataQuality;

use App\Models\EntityAlias;
use App\Models\QuarantinedRow;
use Illuminate\Support\Facades\DB;

/**
 * W9 (WP9.4) — SKU alias suggestions from repeated unknown values.
 *
 * An SKU that is not in the product master but matches exactly ONE product
 * once case, spaces, punctuation and leading zeros are ignored ("00123-a" vs
 * "123A") is almost always the same product written by another system.
 * Suggested, never applied: a person accepts it, which creates an
 * EntityAlias the firewall then applies to every later row.
 */
class AliasSuggester
{
    /** @return array<int,array{alias:string, canonical:string, rows:int, source:string}> */
    public function suggest(int $tenantId, int $limit = 200): array
    {
        $unknown = $this->unknownSkus($tenantId);
        if ($unknown === []) {
            return [];
        }

        $byNorm = [];
        foreach (DB::table('products')->where('tenant_id', $tenantId)->pluck('sku') as $sku) {
            $byNorm[self::normalize((string) $sku)][] = (string) $sku;
        }
        $aliased = EntityAlias::where('tenant_id', $tenantId)->where('entity_type', 'sku')->pluck('alias')
            ->mapWithKeys(fn ($a) => [mb_strtolower(trim((string) $a)) => true])->all();

        $out = [];
        foreach ($unknown as $sku => [$rows, $source]) {
            $matches = $byNorm[self::normalize($sku)] ?? [];
            if (count($matches) !== 1 || $matches[0] === $sku || isset($aliased[mb_strtolower(trim($sku))])) {
                continue;
            }
            $out[] = ['alias' => $sku, 'canonical' => $matches[0], 'rows' => $rows, 'source' => $source];
        }
        usort($out, fn ($a, $b) => $b['rows'] <=> $a['rows']);

        return array_slice($out, 0, $limit);
    }

    /** Accept a suggestion: the firewall maps the alias from now on. */
    public function accept(int $tenantId, string $alias, string $canonical): EntityAlias
    {
        return EntityAlias::updateOrCreate(
            ['tenant_id' => $tenantId, 'entity_type' => 'sku', 'alias' => mb_strtolower(trim($alias))],   // as the quarantine page stores them
            ['canonical' => $canonical],
        );
    }

    public static function normalize(string $sku): string
    {
        $s = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $sku) ?? '');

        return ltrim($s, '0') === '' ? $s : ltrim($s, '0');
    }

    /**
     * Unknown SKUs with how many rows carry them: sold in the last 90 days
     * (loaded with an orphan warning) or held in quarantine as orphans.
     *
     * @return array<string,array{0:int,1:string}>
     */
    private function unknownSkus(int $tenantId): array
    {
        $out = [];
        $sold = DB::select("SELECT sd.sku, COUNT(*) AS n FROM sales_daily sd
            WHERE sd.tenant_id = ? AND sd.date > CURRENT_DATE - 90
              AND NOT EXISTS (SELECT 1 FROM products p WHERE p.tenant_id = sd.tenant_id AND p.sku = sd.sku)
            GROUP BY sd.sku ORDER BY COUNT(*) DESC LIMIT 2000", [$tenantId]);
        foreach ($sold as $r) {
            $out[(string) $r->sku] = [(int) $r->n, 'sales'];
        }

        $quarantined = QuarantinedRow::where('tenant_id', $tenantId)->where('status', QuarantinedRow::STATUS_OPEN)
            ->where('reason_code', Reasons::ORPHAN_REFERENCE)
            ->selectRaw("cleansed_data::jsonb->>'sku' AS sku, COUNT(*) AS n")->groupByRaw("cleansed_data::jsonb->>'sku'")
            ->limit(2000)->get();
        foreach ($quarantined as $r) {
            if ($r->sku !== null && $r->sku !== '') {
                $prev = $out[(string) $r->sku][0] ?? 0;
                $out[(string) $r->sku] = [$prev + (int) $r->n, $prev ? 'sales, quarantine' : 'quarantine'];
            }
        }

        return $out;
    }
}
