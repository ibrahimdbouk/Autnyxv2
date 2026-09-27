<?php

namespace App\Platform\Intelligence\Substitution;

/**
 * How close two products are as substitutes, from the product file alone —
 * the ASSUMPTION used when stockouts and range changes have not shown where a
 * product's buyers go. Never presented as evidence.
 *
 *   same subcategory           0.40   (a product with a subcategory only competes inside it)
 *   same category only         0.15   (when the product has no subcategory)
 *   same brand                +0.25
 *   similar pack (±25%)       +0.20   (same unit: ml, g or pieces; "6x330ml" = 1,980 ml)
 *   similar price (±20%)      +0.15
 *
 * Product facts are arrays with category, subcategory, brand, pack_size, price.
 */
final class ProductSimilarity
{
    public const SAME_KIND     = 0.40;
    public const SAME_CATEGORY = 0.15;
    public const SAME_BRAND    = 0.25;
    public const SIMILAR_PACK  = 0.20;
    public const SIMILAR_PRICE = 0.15;

    /** 0 = not a substitute, up to 1 = as close as the product file can say. */
    public static function closeness(array $a, array $b): float
    {
        $catA = self::norm($a['category'] ?? null);
        if ($catA === '' || $catA !== self::norm($b['category'] ?? null)) {
            return 0.0;
        }
        $subA = self::norm($a['subcategory'] ?? null);
        $subB = self::norm($b['subcategory'] ?? null);
        if ($subA !== '' && $subB !== '' && $subA !== $subB) {
            return 0.0;
        }
        $c = $subA !== '' && $subA === $subB ? self::SAME_KIND : self::SAME_CATEGORY;

        $brandA = self::norm($a['brand'] ?? null);
        if ($brandA !== '' && $brandA === self::norm($b['brand'] ?? null)) {
            $c += self::SAME_BRAND;
        }
        $packA = self::pack($a['pack_size'] ?? null);
        $packB = self::pack($b['pack_size'] ?? null);
        if ($packA !== null && $packB !== null && $packA[1] === $packB[1] && self::near($packA[0], $packB[0], 0.25)) {
            $c += self::SIMILAR_PACK;
        }
        $pa = (float) ($a['price'] ?? 0);
        $pb = (float) ($b['price'] ?? 0);
        if ($pa > 0 && $pb > 0 && self::near($pa, $pb, 0.20)) {
            $c += self::SIMILAR_PRICE;
        }

        return min(1.0, round($c, 4));
    }

    /** Can the product file say anything about substitutes for this product? */
    public static function describable(array $a): bool
    {
        return self::norm($a['subcategory'] ?? null) !== '' || self::norm($a['brand'] ?? null) !== '';
    }

    /** Which attributes two products share, in words ("same subcategory, brand and pack size"). */
    public static function shared(array $a, array $b): array
    {
        $out = [];
        $subA = self::norm($a['subcategory'] ?? null);
        if ($subA !== '' && $subA === self::norm($b['subcategory'] ?? null)) {
            $out[] = 'subcategory';
        }
        $brandA = self::norm($a['brand'] ?? null);
        if ($brandA !== '' && $brandA === self::norm($b['brand'] ?? null)) {
            $out[] = 'brand';
        }
        $packA = self::pack($a['pack_size'] ?? null);
        $packB = self::pack($b['pack_size'] ?? null);
        if ($packA !== null && $packB !== null && $packA[1] === $packB[1] && self::near($packA[0], $packB[0], 0.25)) {
            $out[] = 'pack size';
        }
        $pa = (float) ($a['price'] ?? 0);
        $pb = (float) ($b['price'] ?? 0);
        if ($pa > 0 && $pb > 0 && self::near($pa, $pb, 0.20)) {
            $out[] = 'price';
        }

        return $out;
    }

    /**
     * "500ml" → [500, 'ml'], "1.5 L" → [1500, 'ml'], "6x330ml" → [1980, 'ml'],
     * "1kg" → [1000, 'g'], "12 pcs" → [12, 'pc']; null when it cannot be read.
     *
     * @return array{0:float,1:string}|null
     */
    public static function pack(mixed $raw): ?array
    {
        $s = strtolower(str_replace(',', '.', trim((string) $raw)));
        if ($s === '') {
            return null;
        }
        $mult = 1.0;
        if (preg_match('/^(\d+)\s*[x×]\s*(.+)$/u', $s, $m)) {
            $mult = (float) $m[1];
            $s = trim($m[2]);
        }
        if (! preg_match('/^(\d+(?:\.\d+)?)\s*(ml|cl|l|ltr|litre|liter|g|gr|kg|pcs|pc|pieces|piece|pack|ea)?\b/u', $s, $m)) {
            return null;
        }
        $n = (float) $m[1] * $mult;
        $unit = $m[2] ?? '';

        return match ($unit) {
            'ml'                            => [$n, 'ml'],
            'cl'                            => [$n * 10, 'ml'],
            'l', 'ltr', 'litre', 'liter'    => [$n * 1000, 'ml'],
            'g', 'gr'                       => [$n, 'g'],
            'kg'                            => [$n * 1000, 'g'],
            default                         => [$n, 'pc'],
        };
    }

    private static function near(float $x, float $y, float $tolerance): bool
    {
        $hi = max($x, $y);

        return $hi > 0 && abs($x - $y) / $hi <= $tolerance;
    }

    private static function norm(mixed $v): string
    {
        return mb_strtolower(trim((string) $v));
    }
}
