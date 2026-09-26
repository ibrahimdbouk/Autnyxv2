<?php

namespace App\Models;

use App\Services\Import\CanonicalSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Learned column-mapping memory. Keyed by a source SIGNATURE (the sorted set of
 * source headers) so column order doesn't matter. A confirmed import writes it; the
 * next import recalls it and skips the AI mapper. See claude/data-quality-firewall.md.
 */
class MappingMemory extends Model
{
    protected $fillable = [
        'tenant_id', 'data_type', 'signature', 'mappings', 'header_count', 'last_used_at', 'schema_version',
    ];

    /** WP3.3: only an import at least this clean may teach the memory. */
    public const MIN_SUCCESS_RATE = 0.9;

    protected $casts = [
        'mappings'     => 'array',
        'header_count' => 'integer',
        'schema_version' => 'integer',
        'times_seen'   => 'integer',
        'last_used_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Order-independent fingerprint of a header set. */
    public static function signature(array $headers): string
    {
        $norm = array_values(array_filter(
            array_map(fn ($h) => mb_strtolower(trim((string) $h)), $headers),
            fn ($h) => $h !== '',
        ));
        sort($norm);

        return sha1(implode('|', $norm));
    }

    /**
     * Recall a learned mapping for this header set. Exact signature → fully learned;
     * otherwise the best-overlapping memory (≥ 60%) → learned for known headers, the
     * rest flagged for one-time confirmation. Returns map()-shaped entries, or null.
     *
     * @return array<int,array<string,mixed>>|null
     */
    public static function recall(int $tenantId, string $dataType, array $headers): ?array
    {
        // WP3.3: memories learned under an older schema / alias set are ignored.
        $current = static::where('tenant_id', $tenantId)
            ->where('data_type', $dataType)
            ->where('schema_version', CanonicalSchema::versionFor($dataType));

        $exact = (clone $current)->where('signature', static::signature($headers))->first();

        if ($exact) {
            $exact->forceFill(['last_used_at' => now()])->save();
            $exact->increment('times_seen');

            return static::project($exact->mappings ?? [], $headers);
        }

        // Drift: reuse the closest known mapping and flag the new columns.
        $best = null;
        $bestScore = 0.0;
        foreach ($current->get() as $mem) {
            $score = static::overlap($mem->mappings ?? [], $headers);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $mem;
            }
        }
        if ($best !== null && $bestScore >= 0.6) {
            $best->forceFill(['last_used_at' => now()])->save();

            return static::project($best->mappings, $headers);
        }

        return null;
    }

    /**
     * Learn (or refresh) the mapping from a completed import. Best-effort.
     *
     * WP3.3 (audit H24): only when a PERSON confirmed the mapping and at least
     * 90% of the rows landed — an auto-accepted or mostly-failing mapping
     * must never be replayed at confidence 1.0. The signature covers EVERY
     * column of the file (skipped ones included), exactly as recall() sees it.
     */
    public static function rememberFromImport(Import $import): void
    {
        if (empty(CanonicalSchema::forType($import->data_type)) || $import->mapping_confirmed_at === null) {
            return;
        }

        $done = (int) $import->imported_rows + (int) $import->duplicate_rows;
        $seen = $done + (int) $import->failed_rows + (int) $import->quarantined_rows;
        if ($seen === 0 || $done / $seen < self::MIN_SUCCESS_RATE) {
            return;
        }

        $maps = $import->columnMaps()->orderBy('sort_order')->get();
        if ($maps->isEmpty() || $maps->whereNotNull('target_field')->where('is_skipped', false)->isEmpty()) {
            return;
        }

        $headers  = $maps->pluck('source_header')->all();
        $mappings = $maps->map(fn ($m) => [
            'source_header' => $m->source_header,
            'target_field'  => $m->is_skipped ? null : $m->target_field,
        ])->values()->all();

        $mem = static::updateOrCreate(
            ['tenant_id' => $import->tenant_id, 'data_type' => $import->data_type, 'signature' => static::signature($headers)],
            ['mappings' => $mappings, 'header_count' => count($headers), 'last_used_at' => now(), 'schema_version' => CanonicalSchema::versionFor($import->data_type)],
        );
        $mem->increment('times_seen');
    }

    /** Build map()-shaped entries for the incoming headers from a learned mapping. */
    private static function project(array $mappings, array $headers): array
    {
        $byHeader = [];
        foreach ($mappings as $m) {
            $byHeader[mb_strtolower(trim((string) ($m['source_header'] ?? '')))] = $m['target_field'] ?? null;
        }

        $out = [];
        foreach ($headers as $i => $h) {
            $key = mb_strtolower(trim((string) $h));
            $known = array_key_exists($key, $byHeader);
            $target = $known ? $byHeader[$key] : null;
            $out[] = [
                'source_header' => $h,
                'target_field'  => $target,
                // A new column is an open question for the reviewer (0.5 → "uncertain").
                'confidence'    => $known ? 1.0 : 0.5,
                'reasoning'     => match (true) {
                    $known && $target !== null => 'Learned from a previously confirmed import',
                    $known                     => 'Skipped in the previously confirmed import',
                    default                    => 'New column since the last confirmed import — please confirm',
                },
                'is_confirmed'  => $known,
                'sort_order'    => $i,
            ];
        }

        return $out;
    }

    /** Jaccard overlap between a memory's header set and an incoming header set. */
    private static function overlap(array $mappings, array $headers): float
    {
        $a = [];
        foreach ($mappings as $m) {
            $h = mb_strtolower(trim((string) ($m['source_header'] ?? '')));
            if ($h !== '') {
                $a[$h] = true;
            }
        }
        $b = [];
        foreach ($headers as $h) {
            $h = mb_strtolower(trim((string) $h));
            if ($h !== '') {
                $b[$h] = true;
            }
        }
        if ($a === [] || $b === []) {
            return 0.0;
        }

        $inter = count(array_intersect_key($a, $b));
        $union = count($a + $b);

        return $union === 0 ? 0.0 : $inter / $union;
    }
}
