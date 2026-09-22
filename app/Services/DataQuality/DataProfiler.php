<?php

namespace App\Services\DataQuality;

use App\Services\Import\CanonicalSchema;

/**
 * Profiles a sample of mapped rows into per-column stats (fill rate, distinct,
 * invalid-type rate) for the column-health view. Computed on the first chunk — a
 * representative sample — so it stays memory-safe on big files. See
 * claude/data-quality-firewall.md.
 */
class DataProfiler
{
    private const DISTINCT_CAP = 50;

    /** @param array<int,array<string,mixed>> $rows @return array<string,array> */
    public function profile(string $dataType, array $rows): array
    {
        $fields = array_keys(CanonicalSchema::forType($dataType));
        $out    = [];
        $n      = max(1, count($rows));

        foreach ($fields as $field) {
            $type     = FieldTypes::of($field);
            $blanks   = 0;
            $invalid  = 0;
            $distinct = [];

            foreach ($rows as $row) {
                $v = $row[$field] ?? null;
                if ($v === null || trim((string) $v) === '') {
                    $blanks++;
                    continue;
                }
                $v = (string) $v;
                if (count($distinct) < self::DISTINCT_CAP) {
                    $distinct[$v] = true;
                }
                if ($type === FieldTypes::DATE && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                    $invalid++;
                } elseif (($type === FieldTypes::NUMBER || $type === FieldTypes::INT) && ! is_numeric($v)) {
                    $invalid++;
                }
            }

            $out[$field] = [
                'type'         => $type,
                'fill_pct'     => (int) round(100 * ($n - $blanks) / $n),
                'blank_pct'    => (int) round(100 * $blanks / $n),
                'invalid_pct'  => (int) round(100 * $invalid / $n),
                'distinct'     => count($distinct) . (count($distinct) >= self::DISTINCT_CAP ? '+' : ''),
            ];
        }

        return $out;
    }
}
