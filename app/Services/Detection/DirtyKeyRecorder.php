<?php

namespace App\Services\Detection;

use App\Models\DetectionDirtyKey;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Marks (store, SKU) subjects dirty so the next detection run knows what changed.
 *
 * Idempotent: a subject queued many times before the next run stays a single row
 * (ON CONFLICT DO NOTHING against the unique (tenant_id, store_id, sku) index).
 * Best-effort — recording must never break the import that triggered it.
 *
 * Slice 1: populated by ImportProcessorService (imports + rollbacks). The queue
 * is not yet consumed; detection still runs the full scan.
 * See claude/incremental-detection-design.md.
 */
class DirtyKeyRecorder
{
    /** Guard rail against a pathological single import flooding the queue in one call. */
    private const INSERT_CHUNK = 1000;

    /**
     * Queue subjects for re-detection.
     *
     * @param  array<int,array{store_id?:int|null,sku?:string|null}>  $keys
     * @return int  rows actually inserted (deduped)
     */
    public function record(int $tenantId, array $keys, string $reason = DetectionDirtyKey::REASON_IMPORT): int
    {
        if ($keys === []) {
            return 0;
        }

        $now  = now();
        $rows = [];
        $seen = [];

        foreach ($keys as $k) {
            $storeId = $k['store_id'] ?? null;
            $sku     = $k['sku'] ?? null;

            if ($storeId === null && $sku === null) {
                continue; // nothing to key on
            }

            $sig = $storeId . '|' . $sku;
            if (isset($seen[$sig])) {
                continue; // local dedupe before hitting the DB
            }
            $seen[$sig] = true;

            $rows[] = [
                'tenant_id'  => $tenantId,
                'store_id'   => $storeId,
                'sku'        => $sku,
                'reason'     => $reason,
                'created_at' => $now,
            ];
        }

        if ($rows === []) {
            return 0;
        }

        $inserted = 0;
        try {
            foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
                $inserted += DB::table('detection_dirty_keys')->insertOrIgnore($chunk);
            }
        } catch (Throwable $e) {
            // Best-effort: a full-sweep run still covers anything we failed to queue.
            return 0;
        }

        return $inserted;
    }
}
