<?php

namespace App\Services\Assortment;

use App\Models\AssortmentConstraint;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * v2.2 — commercial constraints: the deals a buyer has to keep, as hard limits
 * the rules never break.
 *
 *   keep          this product stays (a contracted line), until the date
 *   brand_min     at least N products of a brand on the shelf (in a category,
 *                 or in every category the brand is in) — own label included
 *   supplier_min  at least N products from a supplier, the same way
 *
 * Scope: one store, every store of a format, or every store. A deal past its
 * date no longer binds.
 *
 * How a floor protects: with N or fewer of the brand on the shelf, each of them
 * stays (removing any would break it); with more, the N that sell best stay and
 * the rest may go. One rule for single decisions, range plans and the Studio.
 *
 * What it costs: a delist the rules would have proposed, held back by a deal,
 * is valued as usual and added to that deal's cost — "keeping this deal costs
 * 4–9K a year here" — and nothing is proposed.
 */
class CommercialConstraints
{
    /** @return array<int,array<string,mixed>> the deals in force on a date, as plain rows */
    public static function active(int $tenantId, string $asOf): array
    {
        return AssortmentConstraint::where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->whereNull('until')->orWhere('until', '>=', $asOf))
            ->orderBy('id')->get()
            ->map(fn (AssortmentConstraint $c) => [
                'id' => (int) $c->id, 'kind' => $c->kind, 'sku' => $c->sku, 'brand' => self::norm($c->brand), 'brand_label' => $c->brand,
                'supplier' => self::norm($c->supplier), 'supplier_label' => $c->supplier, 'category' => $c->category ? trim($c->category) : null,
                'store_id' => $c->store_id ? (int) $c->store_id : null, 'format' => $c->format, 'min' => (int) ($c->min_count ?? 0),
                'until' => $c->until?->toDateString(), 'note' => $c->note,
            ])->all();
    }

    /** @return array<int,array<int,array>> store id => the deals that apply to it */
    public static function byStore(int $tenantId, array $active): array
    {
        if ($active === []) {
            return [];
        }
        $out = [];
        foreach (DB::table('stores')->where('tenant_id', $tenantId)->get(['id', 'format']) as $s) {
            $f = ShelfSpace::formatSlug($s->format);
            $out[(int) $s->id] = array_values(array_filter($active, fn ($c) => $c['store_id'] !== null
                ? $c['store_id'] === (int) $s->id
                : ($c['format'] === null || $c['format'] === $f)));
        }

        return $out;
    }

    /**
     * The deal that keeps this product on this shelf, or null.
     *
     * @param  array<int,array>  $rules  the deals for this store
     * @param  array<int,string>  $shelf  the products on the store's shelf for the product's category
     * @param  array<string,array>  $products  sku => facts (category, brand, supplier)
     * @param  array<string,float>  $sales  sku => sales a year on this shelf; empty = protect only when at or under the floor
     * @return array{id:int, why:string}|null
     */
    public static function keeps(array $rules, string $sku, array $shelf, array $products, array $sales = []): ?array
    {
        $p = $products[$sku] ?? null;
        if ($rules === [] || $p === null) {
            return null;
        }
        foreach ($rules as $c) {
            if ($c['kind'] === AssortmentConstraint::KEEP) {
                if ((string) $c['sku'] === $sku) {
                    return ['id' => $c['id'], 'why' => 'Deal: keep this line' . ($c['until'] ? ' until ' . Carbon::parse($c['until'])->format('j M Y') : '') . ($c['note'] ? ' (' . $c['note'] . ')' : '')];
                }

                continue;
            }
            $field = $c['kind'] === AssortmentConstraint::BRAND_MIN ? 'brand' : 'supplier';
            if ($c[$field] === null || self::norm($p[$field] ?? null) !== $c[$field] || ($c['category'] !== null && $c['category'] !== ($p['category'] ?? null))) {
                continue;
            }
            $same = array_values(array_filter($shelf, fn ($s) => self::norm($products[$s][$field] ?? null) === $c[$field]
                && ($products[$s]['category'] ?? null) === ($p['category'] ?? null)));
            if (! in_array($sku, $same, true)) {
                $same[] = $sku;
            }
            $label = ($c[$field . '_label'] ?? $c[$field]);
            $why = ($field === 'brand' ? 'Deal: at least ' . $c['min'] . ' ' . $label . ' products' : 'Deal: at least ' . $c['min'] . ' products from ' . $label)
                . ' in ' . ($p['category'] ?? 'the category') . ($c['until'] ? ' until ' . Carbon::parse($c['until'])->format('j M Y') : '');
            if (count($same) <= $c['min']) {
                return ['id' => $c['id'], 'why' => $why];
            }
            if ($sales !== []) {
                usort($same, fn ($a, $b) => [-(float) ($sales[$a] ?? 0), $a] <=> [-(float) ($sales[$b] ?? 0), $b]);
                if (in_array($sku, array_slice($same, 0, $c['min']), true)) {
                    return ['id' => $c['id'], 'why' => $why . ' (one of the ' . $c['min'] . ' that sell best)'];
                }
            }
        }

        return null;
    }

    /** Write each deal's cost from a run: what the delists it held back were worth. */
    public static function recordCosts(int $tenantId, array $kept, string $asOf): void
    {
        foreach (AssortmentConstraint::where('tenant_id', $tenantId)->get(['id', 'cost']) as $c) {
            $k = $kept[$c->id] ?? null;
            $c->forceFill(['cost' => $k === null
                ? ['low' => 0, 'high' => 0, 'decisions' => 0, 'as_of' => $asOf]
                : ['low' => round($k['low'], 2), 'high' => round($k['high'], 2), 'decisions' => $k['n'], 'as_of' => $asOf]])->save();
        }
    }

    private static function norm(?string $v): ?string
    {
        $v = Str::of((string) $v)->lower()->squish()->toString();

        return $v === '' ? null : $v;
    }
}
