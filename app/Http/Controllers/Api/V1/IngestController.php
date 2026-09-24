<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Import;
use App\Services\Integrations\PipelineIngestor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

/**
 * Push data INTO Autnyx (scope write:ingest). Rows run through the standard import
 * pipeline — same validation, auto-mapping, and async detection as any other import.
 *
 * WP2.3 (audit H13):
 *  • processing is queued — the call returns 202 with an import id to poll at
 *    GET /api/v1/imports/{id}, instead of doing the whole import in the request;
 *  • an `Idempotency-Key` header makes retries safe for 24h (same key → same import);
 *  • the `users` data type is not accepted over the API (account and role
 *    changes are made in the app by an administrator).
 */
class IngestController extends BaseApiController
{
    private const IDEMPOTENCY_TTL = 86400;

    public function store(Request $request, PipelineIngestor $ingestor)
    {
        $allowedTypes = array_diff(array_keys(Import::dataTypeLabels()), [Import::TYPE_USERS]);

        $data = Validator::make($request->all(), [
            'data_type' => ['required', 'string', 'in:' . implode(',', $allowedTypes)],
            'rows'      => ['required', 'array', 'min:1', 'max:' . PipelineIngestor::MAX_ROWS],
            'rows.*'    => ['array'],
        ])->validate();

        $tenantId = $this->tenantId($request);
        $idemKey = trim((string) $request->header('Idempotency-Key', ''));
        $cacheKey = $idemKey !== '' ? 'api-ingest:' . $tenantId . ':' . hash('sha256', $idemKey) : null;

        if ($cacheKey && ($existingId = Cache::get($cacheKey))) {
            $existing = Import::where('tenant_id', $tenantId)->find($existingId);
            if ($existing) {
                return response()->json($this->payload($existing) + ['idempotent_replay' => true], 200);
            }
        }

        $lock = $cacheKey ? Cache::lock($cacheKey . ':lock', 30) : null;
        if ($lock && ! $lock->get()) {
            return response()->json(['error' => 'conflict', 'message' => 'A request with this Idempotency-Key is already being processed.'], 409);
        }

        try {
            $import = $ingestor->ingestRows($tenantId, $data['data_type'], $data['rows'], 'apiingest', queue: true);
            if (! $import) {
                return response()->json(['error' => 'no_rows', 'message' => 'No rows were ingested.'], 422);
            }
            if ($cacheKey) {
                Cache::put($cacheKey, $import->id, self::IDEMPOTENCY_TTL);
            }
        } finally {
            $lock?->release();
        }

        return response()->json($this->payload($import), 202);
    }

    /** GET /api/v1/imports/{id} — poll a queued ingest (scope write:ingest). */
    public function show(Request $request, int $id)
    {
        $import = Import::where('tenant_id', $this->tenantId($request))->findOrFail($id);

        return response()->json($this->payload($import));
    }

    private function payload(Import $import): array
    {
        return [
            'import_id'     => $import->id,
            'data_type'     => $import->data_type,
            'status'        => $import->status,
            'total_rows'    => (int) $import->total_rows,
            'imported_rows' => (int) $import->imported_rows,
            'failed_rows'   => (int) $import->failed_rows,
        ];
    }
}
